#!/usr/bin/env python3
"""Deliver live alerts to configured Discord and generic HTTPS webhooks.

The dispatcher deliberately requires an explicit live-delivery flag.  Manual
tests, dry runs, previews, and direct CLI invocation cannot send webhooks unless
the caller also identifies the event as a live NWS or Xweather alert.
"""

import hashlib
import hmac
import http.client
import fcntl
import ipaddress
import json
import math
import os
import pwd
import re
import signal
import socket
import ssl
import stat
import sys
import tempfile
import threading
import time
from contextlib import contextmanager, nullcontext
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

DEFAULT_CONFIG = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config")
MAX_DESTINATIONS = 10
MAX_PAYLOAD_BYTES = 64 * 1024
DEFAULT_TIMEOUT = 2.0
DEFAULT_DELIVERY_BUDGET = 8.0
MAX_ATTEMPTS = 2
MAX_RETRY_DELAY = 0.5
WORKER_EXIT_SAFETY_SECONDS = 2.0
MAX_RETRY_DELIVERIES = 500
MAX_RETRY_STATE_BYTES = 4 * 1024 * 1024
MAX_RETRY_RECORDS_PER_RUN = 3
COMPLETED_RETRY_RETENTION_SECONDS = 7 * 86400
PENDING_RETRY_MAX_AGE_SECONDS = 3600
USER_AGENT = "SouthlandServers-Mass-Notifications-Server/0.1.2-beta"
DISCORD_HOSTS = {"discord.com", "discordapp.com", "canary.discord.com", "ptb.discord.com"}
DISCORD_PATH = re.compile(r"^/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+$")
SOUTHLAND_SERVERS_LOGO_URL = "https://southlandservers.xyz/images/webhook.png"
# Preserve the public constant used by older integrations while making the
# branding contract explicit: every Discord payload uses the Southland Servers
# logo as the webhook profile picture.
DISCORD_AVATAR_URL = SOUTHLAND_SERVERS_LOGO_URL
DISCORD_EMBED_IMAGE_URL = "https://southlandservers.xyz/images/webhook_proxy.png"
DNS_NAME = re.compile(
    r"^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+"
    r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$",
    re.IGNORECASE,
)


class DestinationError(RuntimeError):
    """A destination failure whose message is safe to expose in local logs."""

    def __init__(self, code):
        super().__init__(str(code))
        self.code = str(code)


class DeliveryBudgetExceeded(RuntimeError):
    """Raised by the process-level timer when the delivery budget expires."""


class RetryStateError(RuntimeError):
    """Raised when durable external-delivery state cannot be trusted."""


def _default_email_sender(*args, **kwargs):
    from sls_branded_email import send_branded_email

    return send_branded_email(*args, **kwargs)


def _safe_budget(value, default=DEFAULT_DELIVERY_BUDGET):
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        parsed = float(default)
    if not math.isfinite(parsed):
        parsed = float(default)
    return min(DEFAULT_DELIVERY_BUDGET, max(0.0, parsed))


def _effective_delivery_budget(requested, wall_clock=time.time):
    """Clamp delivery to both its own budget and the scheduler's exit margin."""
    budget = _safe_budget(requested)
    raw_worker_deadline = os.environ.get("SLS_WORKER_DEADLINE_EPOCH", "").strip()
    if raw_worker_deadline:
        try:
            worker_remaining = float(raw_worker_deadline) - float(wall_clock()) - WORKER_EXIT_SAFETY_SECONDS
        except (TypeError, ValueError):
            worker_remaining = 0.0
        budget = min(budget, max(0.0, worker_remaining))
    return budget


@contextmanager
def _wall_clock_budget(seconds):
    """Interrupt blocking DNS/TLS/socket work at the process delivery deadline.

    Production callers run in the main thread on Debian.  The monotonic checks
    in `_deliver` remain the fallback for unit tests and unusual embedded use.
    """
    seconds = max(0.0, float(seconds))
    can_alarm = (
        seconds > 0
        and hasattr(signal, "SIGALRM")
        and hasattr(signal, "setitimer")
        and threading.current_thread() is threading.main_thread()
    )
    if not can_alarm:
        yield
        return

    started = time.monotonic()
    previous_handler = signal.getsignal(signal.SIGALRM)
    previous_delay, previous_interval = signal.getitimer(signal.ITIMER_REAL)

    def expire(_signum, _frame):
        raise DeliveryBudgetExceeded("delivery_budget_exhausted")

    signal.signal(signal.SIGALRM, expire)
    alarm_delay = seconds if previous_delay <= 0 else min(seconds, previous_delay)
    signal.setitimer(signal.ITIMER_REAL, max(0.001, alarm_delay))
    try:
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous_handler)
        if previous_delay > 0:
            elapsed = max(0.0, time.monotonic() - started)
            signal.setitimer(signal.ITIMER_REAL, max(0.001, previous_delay - elapsed), previous_interval)


class _PinnedHTTPSConnection(http.client.HTTPSConnection):
    """HTTPS connection pinned to a pre-validated address with hostname TLS."""

    def __init__(self, hostname, address, timeout):
        context = ssl.create_default_context()
        context.check_hostname = True
        context.verify_mode = ssl.CERT_REQUIRED
        super().__init__(hostname, port=443, timeout=timeout, context=context)
        self._pinned_address = address

    def connect(self):
        raw_socket = socket.create_connection((self._pinned_address, self.port), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw_socket, server_hostname=self.host)
        except Exception:
            raw_socket.close()
            raise


def _text(value, limit=512):
    value = re.sub(r"[\x00-\x1f\x7f]+", " ", str(value or ""))
    return re.sub(r"\s+", " ", value).strip()[:limit]


def _enabled(value):
    return str(value if value is not None else "1").strip().lower() not in {"0", "false", "no", "off", ""}


def _safe_id(value, kind, name):
    identifier = re.sub(r"[^A-Za-z0-9_-]", "", str(value or ""))[:64]
    if identifier:
        return identifier
    digest = hashlib.sha256(f"{kind}|{name}".encode("utf-8")).hexdigest()[:16]
    return f"{kind}_{digest}"


def _normalize_timestamp(value):
    try:
        parsed = datetime.fromisoformat(str(value or "").replace("Z", "+00:00"))
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)
        return parsed.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")
    except (TypeError, ValueError):
        return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def _normalized_event_id(value, source, subject, event, timestamp):
    identifier = re.sub(r"[^A-Za-z0-9_.:-]", "", str(value or ""))[:128]
    if identifier:
        return identifier
    material = "|".join((_text(source, 80), _text(subject, 256), _text(event, 160), _normalize_timestamp(timestamp)))
    return "sls-" + hashlib.sha256(material.encode("utf-8")).hexdigest()[:32]


def _public_addresses(hostname, resolver=socket.getaddrinfo):
    try:
        records = resolver(hostname, 443, type=socket.SOCK_STREAM)
    except (OSError, socket.gaierror) as exc:
        raise DestinationError("dns_failure") from exc
    addresses = []
    for record in records:
        try:
            address = str(record[4][0]).split("%", 1)[0]
            parsed = ipaddress.ip_address(address)
        except (IndexError, TypeError, ValueError) as exc:
            raise DestinationError("dns_invalid_address") from exc
        if not parsed.is_global:
            raise DestinationError("private_address_blocked")
        canonical = str(parsed)
        if canonical not in addresses:
            addresses.append(canonical)
    if not addresses:
        raise DestinationError("dns_no_addresses")
    return addresses


def _validated_url(value, kind, resolver=socket.getaddrinfo):
    raw = str(value or "").strip()
    if not raw or len(raw) > 2048 or re.search(r"[\x00-\x20\x7f]", raw):
        raise DestinationError("invalid_url")
    try:
        parsed = urlsplit(raw)
        port = parsed.port
    except ValueError as exc:
        raise DestinationError("invalid_url") from exc
    hostname = str(parsed.hostname or "").lower().rstrip(".")
    if (
        parsed.scheme.lower() != "https"
        or not hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.fragment
        or (port is not None and port != 443)
    ):
        raise DestinationError("invalid_url")
    try:
        ipaddress.ip_address(hostname)
        raise DestinationError("ip_literal_blocked")
    except ValueError:
        pass
    if not DNS_NAME.fullmatch(hostname) or hostname.endswith(".local"):
        raise DestinationError("invalid_hostname")
    if kind == "discord":
        if hostname not in DISCORD_HOSTS or parsed.query or not DISCORD_PATH.fullmatch(parsed.path):
            raise DestinationError("invalid_discord_url")
    addresses = _public_addresses(hostname, resolver)
    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query
    return hostname, path, addresses


def alert_profile(subject, body, event="", severity=""):
    text = " ".join((str(subject), str(body), str(event), str(severity))).lower()
    profiles = (
        (("tornado",), 0x991B1B, "🌪️", "EXTREME WEATHER"),
        (("severe thunderstorm", "thunderstorm warning", "severe storm"), 0xC2410C, "⛈️", "SEVERE WEATHER"),
        (("flash flood", "flood warning", "coastal flood"), 0x0369A1, "🌊", "FLOOD WARNING"),
        (("winter storm", "blizzard", "ice storm", "snow squall"), 0x1D4ED8, "❄️", "WINTER WEATHER"),
        (("fire warning", "red flag", "wildfire"), 0xB91C1C, "🔥", "FIRE WEATHER"),
        (("lightning",), 0xB45309, "⚡", "LIGHTNING WARNING"),
    )
    for words, color, icon, label in profiles:
        if any(word in text for word in words):
            return color, icon, label
    return 0x6D28D9, "📢", _text(severity, 80).upper() or "MASS NOTIFICATION"


def public_logo_url(config):
    # Keep this compatibility helper for callers that previously expected the
    # PBX-hosted logo. Discord must be able to retrieve webhook branding even
    # when a PBX is private, behind NAT, or has changed its local asset cache.
    del config
    return DISCORD_AVATAR_URL


def _compact_description(body, subject):
    lines = [_text(line, 400) for line in str(body).splitlines()]
    lines = [line for line in lines if line]
    return ("\n".join(lines[:4]) if lines else _text(subject, 900))[:900]


def build_discord_payload(config, subject, body, event="", severity="", fields=None, timestamp=""):
    color, icon, urgency = alert_profile(subject, body, event, severity)
    avatar_url = public_logo_url(config)
    embed_fields = []
    for name, value in fields or []:
        normalized = _text(value, 320)
        if normalized:
            embed_fields.append({"name": _text(name, 256), "value": normalized, "inline": len(normalized) <= 42})
        if len(embed_fields) >= 6:
            break
    embed = {
        "author": {"name": "Southland Servers Group • SLS Mass Notification System"},
        "title": f"{icon} {_text(subject, 245)}"[:256],
        "description": _compact_description(body, subject),
        "color": color,
        "fields": embed_fields,
        "footer": {
            "text": f"{urgency} • SLS Mass Notification System"[:2048],
            "icon_url": avatar_url,
        },
        "timestamp": _normalize_timestamp(timestamp),
    }
    embed["author"]["icon_url"] = avatar_url
    # Discord does not expose image dimensions in webhook payloads. A thumbnail
    # keeps the branding visible without turning the square logo into a large,
    # full-width card image.
    embed["thumbnail"] = {"url": DISCORD_EMBED_IMAGE_URL}
    payload = {
        "username": "SLS Mass Notification System",
        "avatar_url": avatar_url,
        "embeds": [embed],
    }
    return payload


def build_announcement_discord_payload(config, title, body, background_color="#6d28d9", fields=None, timestamp=""):
    """Build the bounded Discord-compatible card used by Dashboard announcements."""
    normalized_title = _text(title, 220) or "Announcement"
    payload = build_discord_payload(
        config,
        normalized_title,
        body,
        "Announcement",
        "Information",
        fields,
        timestamp,
    )
    color = str(background_color or "").strip()
    if re.fullmatch(r"#[0-9A-Fa-f]{6}", color):
        payload["embeds"][0]["color"] = int(color[1:], 16)
    payload["embeds"][0]["footer"]["text"] = "DASHBOARD ANNOUNCEMENT • SLS Mass Notification System"
    return payload


def _bounded_details(details):
    output = {}
    source = details if isinstance(details, dict) else {}
    for key, value in list(source.items())[:20]:
        safe_key = re.sub(r"[^A-Za-z0-9_.-]", "_", _text(key, 48))
        if not safe_key or any(word in safe_key.lower() for word in ("secret", "token", "password", "credential", "webhook_url")):
            continue
        if isinstance(value, bool) or value is None:
            output[safe_key] = value
        elif isinstance(value, (int, float)):
            if isinstance(value, float) and not math.isfinite(value):
                continue
            output[safe_key] = value
        elif isinstance(value, (list, tuple)):
            output[safe_key] = [_text(item, 160) for item in list(value)[:20]]
        else:
            output[safe_key] = _text(value, 512)
    return output


def build_generic_payload(subject, body, event="", severity="", source="", event_id="", timestamp="", details=None):
    color, _icon, _label = alert_profile(subject, body, event, severity)
    normalized_timestamp = _normalize_timestamp(timestamp)
    normalized_event_id = _normalized_event_id(event_id, source, subject, event, normalized_timestamp)
    payload = {
        "schema": "com.southlandservers.massnotify.event.v1",
        "schema_version": 1,
        "event_id": normalized_event_id,
        "occurred_at": normalized_timestamp,
        "test": False,
        "source": _text(source, 80),
        "alert": {
            "kind": "lightning" if "xweather" in str(source).lower() or "lightning" in str(event).lower() else "weather",
            "title": _text(subject, 256),
            "message": _text(body, 2000),
            "event": _text(event, 160),
            "severity": _text(severity, 80),
            "state": _text((details or {}).get("storm_state") if isinstance(details, dict) else "", 80),
        },
        "details": _bounded_details(details),
        "presentation": {"accent_color": f"#{color:06x}"},
    }
    encoded = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    if len(encoded) > MAX_PAYLOAD_BYTES:
        raise DestinationError("payload_too_large")
    return payload


def webhook_auth_headers(authentication, body, event_id, now=None):
    headers = {}
    for field in ('bearer_token', 'signing_secret'):
        secret = authentication.get(field, '')
        if not isinstance(secret, str) or len(secret) > 512 or re.search(r'[^\x21-\x7e]', secret):
            raise DestinationError('invalid_webhook_authentication')
    if authentication.get('bearer_token'):
        headers['Authorization'] = 'Bearer ' + authentication['bearer_token']
    if authentication.get('signing_secret'):
        timestamp = str(int(time.time() if now is None else now))
        material = timestamp.encode() + b'.' + event_id.encode() + b'.' + body
        digest = hmac.new(authentication['signing_secret'].encode(), material, hashlib.sha256).hexdigest()
        headers.update({'X-SLS-Timestamp': timestamp, 'X-SLS-Signature': 'sha256=' + digest, 'X-SLS-Event-ID': event_id})
    return headers


def _request_once(url, kind, payload, timeout=DEFAULT_TIMEOUT, resolver=socket.getaddrinfo, idempotency_key="", authentication=None):
    request_deadline = time.monotonic() + max(0.1, float(timeout))
    hostname, path, addresses = _validated_url(url, kind, resolver)
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    if len(body) > MAX_PAYLOAD_BYTES:
        raise DestinationError("payload_too_large")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": USER_AGENT,
        "Host": hostname,
        "Content-Length": str(len(body)),
        "Idempotency-Key": _normalized_event_id(idempotency_key, kind, "webhook", "", ""),
    }
    if kind == 'generic' and authentication:
        headers.update(webhook_auth_headers(authentication, body, headers['Idempotency-Key']))
    last_category = "network_failure"
    for address in addresses:
        remaining = request_deadline - time.monotonic()
        if remaining <= 0:
            raise DestinationError("request_timeout")
        connection = _PinnedHTTPSConnection(hostname, address, remaining)
        try:
            connection.request("POST", path, body=body, headers=headers)
            remaining = request_deadline - time.monotonic()
            if remaining <= 0:
                raise DestinationError("request_timeout")
            if connection.sock is not None:
                connection.sock.settimeout(remaining)
            response = connection.getresponse()
            status = int(response.status)
            response.read(4096)
            return status, response.getheader("Retry-After", "")
        except DeliveryBudgetExceeded:
            raise
        except DestinationError:
            raise
        except ssl.SSLError:
            last_category = "tls_failure"
        except (TimeoutError, socket.timeout):
            last_category = "request_timeout"
        except (OSError, http.client.HTTPException):
            last_category = "network_failure"
        finally:
            connection.close()
    raise DestinationError(last_category)


def _retry_delay(retry_after, attempt):
    try:
        return min(MAX_RETRY_DELAY, max(0.0, float(retry_after)))
    except (TypeError, ValueError):
        return MAX_RETRY_DELAY if attempt > 0 else 0.0


def _destination_rows(config, kind):
    key = "discord_webhooks" if kind == "discord" else "generic_webhooks"
    raw_rows = config.get(key)
    rows = raw_rows if isinstance(raw_rows, list) else []
    if kind == "discord" and not rows:
        legacy = str(config.get("discord_webhook_url") or "").strip()
        if legacy:
            rows = [{"id": "discord_legacy", "name": "Primary Discord", "url": legacy, "enabled": "1"}]
    output = []
    for index, row in enumerate(rows[:MAX_DESTINATIONS]):
        if not isinstance(row, dict) or not _enabled(row.get("enabled", "1")):
            continue
        name = _text(row.get("name"), 80) or (f"Discord {index + 1}" if kind == "discord" else f"Webhook {index + 1}")
        output.append({
            "kind": kind,
            "id": _safe_id(row.get("id"), kind, name),
            "name": name,
            "url": str(row.get("url") or row.get("webhook_url") or "").strip(),
            "authentication": {key: row.get(key, '') for key in ('bearer_token', 'signing_secret')} if kind == 'generic' else {},
        })
    return output


def _announcement_destination_rows(config):
    raw_rows = config.get("announcement_webhooks")
    rows = raw_rows if isinstance(raw_rows, list) else []
    output = []
    for index, row in enumerate(rows[:MAX_DESTINATIONS]):
        if not isinstance(row, dict) or not _enabled(row.get("enabled", "1")):
            continue
        name = _text(row.get("name"), 80) or f"Announcement Webhook {index + 1}"
        url = str(row.get("url") or row.get("webhook_url") or "").strip()
        try:
            hostname = str(urlsplit(url).hostname or "").lower().rstrip(".")
        except ValueError:
            hostname = ""
        output.append({
            # Discord hosts retain strict webhook-path validation. Other public
            # HTTPS receivers may implement the Discord-compatible JSON schema.
            "kind": "discord" if hostname in DISCORD_HOSTS else "generic",
            "id": _safe_id(row.get("id"), "announcement", name),
            "name": name,
            "url": url,
            "authentication": {key: row.get(key, '') for key in ('bearer_token', 'signing_secret')} if hostname not in DISCORD_HOSTS else {},
        })
    return output


def _budget_failure(row, attempts=0):
    return {
        "type": row["kind"],
        "id": row["id"],
        "name": row["name"],
        "status": "failed",
        "attempts": max(0, int(attempts)),
        "http_status": None,
        "error": "delivery_budget_exhausted",
    }


def _deliver(row, payload, event_id, transport, resolver, sleep, deadline, clock):
    safe = {"type": row["kind"], "id": row["id"], "name": row["name"]}
    for attempt in range(1, MAX_ATTEMPTS + 1):
        remaining = deadline - clock()
        if remaining <= 0:
            return _budget_failure(row, attempt - 1)
        request_timeout = min(DEFAULT_TIMEOUT, remaining)
        try:
            auth = row.get('authentication', {})
            kwargs = {'authentication': auth} if any(auth.values()) else {}
            status, retry_after = transport(row["url"], row["kind"], payload, request_timeout, resolver, event_id, **kwargs)
        except DeliveryBudgetExceeded:
            raise
        except DestinationError as exc:
            if clock() >= deadline:
                return _budget_failure(row, attempt)
            if exc.code in {"network_failure", "tls_failure", "dns_failure", "request_timeout"} and attempt < MAX_ATTEMPTS:
                remaining = deadline - clock()
                if remaining <= 0:
                    return _budget_failure(row, attempt)
                delay = min(_retry_delay("", attempt), remaining)
                if delay > 0:
                    sleep(delay)
                continue
            return {**safe, "status": "failed", "attempts": attempt, "http_status": None, "error": exc.code}
        except Exception:
            if clock() >= deadline:
                return _budget_failure(row, attempt)
            if attempt < MAX_ATTEMPTS:
                remaining = deadline - clock()
                if remaining <= 0:
                    return _budget_failure(row, attempt)
                delay = min(_retry_delay("", attempt), remaining)
                if delay > 0:
                    sleep(delay)
                continue
            return {**safe, "status": "failed", "attempts": attempt, "http_status": None, "error": "transport_failure"}
        if clock() >= deadline:
            return _budget_failure(row, attempt)
        if 200 <= status < 300:
            return {**safe, "status": "accepted", "attempts": attempt, "http_status": status, "error": ""}
        if status in {408, 425, 429} or 500 <= status <= 599:
            if attempt < MAX_ATTEMPTS:
                remaining = deadline - clock()
                if remaining <= 0:
                    return _budget_failure(row, attempt)
                delay = min(_retry_delay(retry_after, attempt), remaining)
                if delay > 0:
                    sleep(delay)
                continue
        error = "redirect_blocked" if 300 <= status <= 399 else "http_failure"
        return {**safe, "status": "failed", "attempts": attempt, "http_status": status, "error": error}
    return {**safe, "status": "failed", "attempts": MAX_ATTEMPTS, "http_status": None, "error": "transport_failure"}


def dispatch_webhook_destinations(
    config,
    subject,
    body,
    event="",
    severity="",
    fields=None,
    timestamp="",
    source="",
    event_id="",
    details=None,
    *,
    live=False,
    test=False,
    dry_run=False,
    transport=_request_once,
    resolver=socket.getaddrinfo,
    sleep=time.sleep,
    budget_seconds=DEFAULT_DELIVERY_BUDGET,
    clock=time.monotonic,
    wall_clock=time.time,
    enforce_wall_clock=True,
    destination_keys=None,
):
    """Send an actual NWS/Xweather alert and return secret-free results."""
    normalized_source = str(source or "").strip().lower()
    if not live or test or dry_run or normalized_source not in {"nws", "weather.gov", "xweather"}:
        return []
    budget = _effective_delivery_budget(budget_seconds, wall_clock)
    normalized_timestamp = _normalize_timestamp(timestamp)
    normalized_event_id = _normalized_event_id(event_id, normalized_source, subject, event, normalized_timestamp)
    discord_payload = build_discord_payload(config, subject, body, event, severity, fields, normalized_timestamp)
    generic_payload = build_generic_payload(subject, body, event, severity, normalized_source, normalized_event_id, normalized_timestamp, details)
    rows = _destination_rows(config, "discord") + _destination_rows(config, "generic")
    if destination_keys is not None:
        selected = {str(value) for value in destination_keys}
        rows = [row for row in rows if f"{row['kind']}:{row['id']}" in selected]
    if not rows:
        return []
    if budget <= 0:
        return [_budget_failure(row) for row in rows]
    deadline = clock() + budget
    results = []
    for index, row in enumerate(rows):
        remaining = deadline - clock()
        if remaining <= 0:
            results.extend(_budget_failure(pending) for pending in rows[index:])
            break
        # A failed first endpoint must not consume the complete worker budget.
        # Divide what remains among all unprocessed rows; fast destinations
        # naturally leave more time for the rows that follow.
        rows_left = len(rows) - index
        row_budget = remaining / rows_left
        row_deadline = clock() + row_budget
        payload = discord_payload if row["kind"] == "discord" else generic_payload
        guard = _wall_clock_budget(row_budget) if enforce_wall_clock else nullcontext()
        try:
            with guard:
                result = _deliver(
                    row,
                    payload,
                    normalized_event_id,
                    transport,
                    resolver,
                    sleep,
                    row_deadline,
                    clock,
                )
        except DeliveryBudgetExceeded:
            result = _budget_failure(row)
        results.append(result)
    return results


def dispatch_announcement_webhooks(
    config,
    title,
    body,
    background_color="#6d28d9",
    fields=None,
    timestamp="",
    event_id="",
    destination_ids=None,
    *,
    source="dashboard",
    live=False,
    test=False,
    dry_run=False,
    transport=_request_once,
    resolver=socket.getaddrinfo,
    sleep=time.sleep,
    budget_seconds=DEFAULT_DELIVERY_BUDGET,
    clock=time.monotonic,
    wall_clock=time.time,
    enforce_wall_clock=True,
):
    """Deliver a real Dashboard announcement to explicitly selected destinations."""
    if not live or test or dry_run or str(source or "").strip().lower() != "dashboard":
        return []
    selected = {
        str(value)
        for value in (destination_ids or [])
        if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", str(value))
    }
    if not selected:
        return []
    rows = [row for row in _announcement_destination_rows(config) if row["id"] in selected]
    if not rows:
        return []
    budget = _effective_delivery_budget(budget_seconds, wall_clock)
    normalized_timestamp = _normalize_timestamp(timestamp)
    normalized_event_id = _normalized_event_id(
        event_id,
        "dashboard",
        title,
        "announcement",
        normalized_timestamp,
    )
    payload = build_announcement_discord_payload(
        config,
        title,
        body,
        background_color,
        fields,
        normalized_timestamp,
    )
    if len(json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")) > MAX_PAYLOAD_BYTES:
        return [
            {**{key: row[key] for key in ("kind", "id", "name")}, "status": "failed", "attempts": 0,
             "http_status": None, "error": "payload_too_large"}
            for row in rows
        ]
    if budget <= 0:
        return [_budget_failure(row) for row in rows]
    deadline = clock() + budget
    results = []
    for index, row in enumerate(rows):
        remaining = deadline - clock()
        if remaining <= 0:
            results.extend(_budget_failure(pending) for pending in rows[index:])
            break
        rows_left = len(rows) - index
        row_budget = remaining / rows_left
        row_deadline = clock() + row_budget
        guard = _wall_clock_budget(row_budget) if enforce_wall_clock else nullcontext()
        try:
            with guard:
                result = _deliver(
                    row,
                    payload,
                    normalized_event_id,
                    transport,
                    resolver,
                    sleep,
                    row_deadline,
                    clock,
                )
        except DeliveryBudgetExceeded:
            result = _budget_failure(row)
        results.append(result)
    return results


def _retry_delivery_key(source, correlation_key):
    normalized_source = str(source or "").strip().lower()
    correlation = str(correlation_key or "").replace("\x00", "")[:1024]
    if normalized_source not in {"nws", "weather.gov", "xweather"} or not correlation:
        raise RetryStateError("invalid_retry_identity")
    digest = hashlib.sha256(f"{normalized_source}|{correlation}".encode("utf-8")).hexdigest()
    return f"{normalized_source}-{digest}"


@contextmanager
def _locked_retry_state(path):
    state_path = Path(path)
    parent = state_path.parent
    if not parent.is_dir():
        raise RetryStateError("retry_state_directory_unavailable")
    lock_path = Path(str(state_path) + ".lock")
    flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(lock_path, flags, 0o640)
    except OSError as exc:
        raise RetryStateError("retry_state_lock_unavailable") from exc
    try:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise RetryStateError("retry_state_lock_invalid")
        os.fchmod(descriptor, 0o640)
        if os.geteuid() == 0:
            account = pwd.getpwnam("asterisk")
            os.fchown(descriptor, account.pw_uid, account.pw_gid)
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        yield state_path
    finally:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_UN)
        finally:
            os.close(descriptor)


def _load_retry_state(path):
    state_path = Path(path)
    flags = os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(state_path, flags)
    except FileNotFoundError:
        return {"version": 1, "attempt_sequence": 0, "deliveries": {}}
    except OSError as exc:
        raise RetryStateError("retry_state_unreadable") from exc
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > MAX_RETRY_STATE_BYTES:
            raise RetryStateError("retry_state_invalid")
        with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
            descriptor = -1
            state = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RetryStateError("retry_state_corrupt") from exc
    finally:
        if descriptor >= 0:
            os.close(descriptor)
    if not isinstance(state, dict) or not isinstance(state.get("deliveries"), dict):
        raise RetryStateError("retry_state_corrupt")
    try:
        attempt_sequence = int(state.get("attempt_sequence", 0) or 0)
    except (TypeError, ValueError) as exc:
        raise RetryStateError("retry_state_corrupt") from exc
    if attempt_sequence < 0:
        raise RetryStateError("retry_state_corrupt")
    return {
        "version": 1,
        "attempt_sequence": attempt_sequence,
        "deliveries": state["deliveries"],
    }


def _write_retry_state(path, state):
    state_path = Path(path)
    encoded = (json.dumps(state, separators=(",", ":"), ensure_ascii=True) + "\n").encode("utf-8")
    if len(encoded) > MAX_RETRY_STATE_BYTES:
        raise RetryStateError("retry_state_too_large")
    temporary_descriptor = -1
    temporary_name = ""
    try:
        temporary_descriptor, temporary_name = tempfile.mkstemp(
            prefix=f".{state_path.name}.", suffix=".tmp", dir=str(state_path.parent)
        )
        os.fchmod(temporary_descriptor, 0o640)
        if os.geteuid() == 0:
            account = pwd.getpwnam("asterisk")
            os.fchown(temporary_descriptor, account.pw_uid, account.pw_gid)
        with os.fdopen(temporary_descriptor, "wb") as handle:
            temporary_descriptor = -1
            handle.write(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_name, state_path)
        temporary_name = ""
        directory_descriptor = os.open(
            state_path.parent,
            os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_DIRECTORY", 0),
        )
        try:
            os.fsync(directory_descriptor)
        finally:
            os.close(directory_descriptor)
    except OSError as exc:
        raise RetryStateError("retry_state_write_failed") from exc
    finally:
        if temporary_descriptor >= 0:
            os.close(temporary_descriptor)
        if temporary_name:
            try:
                os.unlink(temporary_name)
            except FileNotFoundError:
                pass


def _prune_retry_state(state, now=None):
    current = int(time.time() if now is None else now)
    deliveries = state["deliveries"]
    for key, record in list(deliveries.items()):
        if not isinstance(record, dict):
            raise RetryStateError("retry_state_corrupt")
        completed_at = int(record.get("completed_at", 0) or 0)
        created_at = _retry_record_integer(record, 'created_at')
        expires_at = _retry_record_integer(record, 'expires_at') or created_at + PENDING_RETRY_MAX_AGE_SECONDS
        if not completed_at and current >= expires_at:
            record['expired_channels'] = list(record.get('webhook_pending') or []) + (['email'] if record.get('email_pending') else [])
            record.update(completed_at=current, terminal_status='expired', email_pending=False, webhook_pending=[])
            completed_at = current
        if completed_at and completed_at < current - COMPLETED_RETRY_RETENTION_SECONDS:
            deliveries.pop(key, None)
    if len(deliveries) <= MAX_RETRY_DELIVERIES:
        return
    completed = sorted(
        (
            (int(record.get("completed_at", 0) or 0), key)
            for key, record in deliveries.items()
            if int(record.get("completed_at", 0) or 0) > 0
        )
    )
    for _completed_at, key in completed:
        if len(deliveries) <= MAX_RETRY_DELIVERIES:
            break
        deliveries.pop(key, None)
    if len(deliveries) > MAX_RETRY_DELIVERIES:
        raise RetryStateError("retry_state_capacity_exhausted")


def _retry_record_integer(record, field):
    try:
        value = int(record.get(field, 0) or 0)
    except (TypeError, ValueError) as exc:
        raise RetryStateError("retry_state_corrupt") from exc
    if value < 0:
        raise RetryStateError("retry_state_corrupt")
    return value


def _retry_record_order(record):
    sequence = _retry_record_integer(record, "last_attempt_sequence")
    attempted_at = _retry_record_integer(record, "last_attempt_at")
    created_at = _retry_record_integer(record, "created_at")
    # Never-attempted work is oldest.  A legacy record with only a timestamp is
    # next and receives a sequence on this attempt; do not compare its epoch
    # timestamp directly with the small monotonic sequence or it could starve.
    # Sequenced records then rotate in durable least-recently-attempted order,
    # even when wall time ties or moves backwards.
    if sequence == 0:
        return (
            0 if attempted_at == 0 else 1,
            0,
            attempted_at,
            created_at,
        )
    return (
        1,
        1,
        sequence,
        attempted_at,
        created_at,
    )


def _retry_payload(
    subject,
    body,
    event,
    severity,
    fields,
    timestamp,
    source,
    event_id,
    details,
):
    return {
        "subject": _text(subject, 512),
        "body": str(body or "").replace("\x00", "")[:32768],
        "event": _text(event, 160),
        "severity": _text(severity, 80),
        "fields": [[_text(name, 80), _text(value, 320)] for name, value in list(fields or [])[:10]],
        "timestamp": _normalize_timestamp(timestamp),
        "source": str(source or "").strip().lower(),
        "event_id": _normalized_event_id(event_id, source, subject, event, timestamp),
        "details": _bounded_details(details),
    }


def queue_external_delivery(
    state_path,
    config,
    correlation_key,
    subject,
    body,
    event="",
    severity="",
    fields=None,
    timestamp="",
    source="",
    event_id="",
    details=None,
    email_recipients="",
    webhook_destination_keys=None,
    now=None,
):
    """Durably record external work before a caller commits local dedup state."""
    current = int(time.time() if now is None else now)
    delivery_key = _retry_delivery_key(source, correlation_key)
    with _locked_retry_state(state_path) as locked_path:
        state = _load_retry_state(locked_path)
        _prune_retry_state(state, current)
        if delivery_key in state["deliveries"]:
            return delivery_key
        if len(state["deliveries"]) >= MAX_RETRY_DELIVERIES:
            completed = sorted(
                (
                    (int(record.get("completed_at", 0) or 0), key)
                    for key, record in state["deliveries"].items()
                    if isinstance(record, dict) and int(record.get("completed_at", 0) or 0) > 0
                )
            )
            for _completed_at, key in completed:
                state["deliveries"].pop(key, None)
                if len(state["deliveries"]) < MAX_RETRY_DELIVERIES:
                    break
        if len(state["deliveries"]) >= MAX_RETRY_DELIVERIES:
            raise RetryStateError("retry_state_capacity_exhausted")
        configured_webhook_keys = [
            f"{row['kind']}:{row['id']}"
            for row in _destination_rows(config, "discord") + _destination_rows(config, "generic")
        ]
        if webhook_destination_keys is None:
            webhook_keys = configured_webhook_keys
        else:
            selected_webhook_keys = {
                str(value) for value in webhook_destination_keys
                if re.fullmatch(r"(?:discord|generic):[A-Za-z0-9_-]{1,64}", str(value))
            }
            webhook_keys = [key for key in configured_webhook_keys if key in selected_webhook_keys]
        recipients = str(email_recipients or "").replace("\x00", "")[:16384].strip()
        pending_email = bool(recipients)
        pending_webhooks = sorted(set(webhook_keys))
        state["deliveries"][delivery_key] = {
            "created_at": current,
            "expires_at": current + PENDING_RETRY_MAX_AGE_SECONDS,
            "completed_at": 0 if pending_email or pending_webhooks else current,
            "last_attempt_at": 0,
            "last_attempt_sequence": 0,
            "payload": _retry_payload(
                subject, body, event, severity, fields, timestamp, source, event_id, details
            ),
            "email_pending": pending_email,
            "email_recipients": recipients,
            "webhook_pending": pending_webhooks,
        }
        _write_retry_state(locked_path, state)
    return delivery_key


def external_delivery_recorded(state_path, source, correlation_key):
    delivery_key = _retry_delivery_key(source, correlation_key)
    with _locked_retry_state(state_path) as locked_path:
        state = _load_retry_state(locked_path)
        _prune_retry_state(state)
        return delivery_key in state["deliveries"]


def external_delivery_pending(state_path, source, correlation_key):
    delivery_key = _retry_delivery_key(source, correlation_key)
    with _locked_retry_state(state_path) as locked_path:
        state = _load_retry_state(locked_path)
        record = state["deliveries"].get(delivery_key)
        return isinstance(record, dict) and int(record.get("completed_at", 0) or 0) == 0


def retry_external_deliveries(
    state_path,
    config,
    source,
    *,
    live=False,
    test=False,
    dry_run=False,
    preferred_correlation_key="",
    email_sender=_default_email_sender,
    webhook_dispatcher=dispatch_webhook_destinations,
    max_records=MAX_RETRY_RECORDS_PER_RUN,
):
    """Retry only pending external channels; never invoke local PBX channels."""
    normalized_source = str(source or "").strip().lower()
    if not live or test or dry_run or normalized_source not in {"nws", "weather.gov", "xweather"}:
        return {"results": [], "pending": 0}
    preferred_key = (
        _retry_delivery_key(normalized_source, preferred_correlation_key)
        if preferred_correlation_key
        else ""
    )
    safe_results = []
    with _locked_retry_state(state_path) as locked_path:
        state = _load_retry_state(locked_path)
        _prune_retry_state(state)
        records = [
            (key, record)
            for key, record in state["deliveries"].items()
            if isinstance(record, dict)
            and int(record.get("completed_at", 0) or 0) == 0
            and str((record.get("payload") or {}).get("source") or "").lower() == normalized_source
        ]
        records.sort(key=lambda item: (
            0 if item[0] == preferred_key else 1,
            *_retry_record_order(item[1]),
            item[0],
        ))
        attempt_sequence = int(state.get("attempt_sequence", 0) or 0)
        for record in state["deliveries"].values():
            if isinstance(record, dict):
                attempt_sequence = max(
                    attempt_sequence,
                    _retry_record_integer(record, "last_attempt_sequence"),
                )
        for delivery_key, record in records[: max(1, min(10, int(max_records)))]:
            attempt_sequence += 1
            state["attempt_sequence"] = attempt_sequence
            record["last_attempt_at"] = max(0, int(time.time()))
            record["last_attempt_sequence"] = attempt_sequence
            # Persist scheduling before invoking a channel.  A timeout or crash
            # therefore moves this record behind untouched work on the next run.
            _write_retry_state(locked_path, state)
            payload = record.get("payload") if isinstance(record.get("payload"), dict) else {}
            if record.get("email_pending"):
                try:
                    sent = email_sender(
                        config,
                        payload.get("subject", ""),
                        payload.get("body", ""),
                        payload.get("event", ""),
                        payload.get("severity", ""),
                        record.get("email_recipients", ""),
                    )
                except Exception:
                    sent = False
                safe_results.append({
                    "delivery": delivery_key,
                    "type": "email",
                    "id": "email",
                    "status": "accepted" if sent else "failed",
                    "error": "" if sent else "submission_failed",
                })
                if sent:
                    record["email_pending"] = False
                    _write_retry_state(locked_path, state)

            configured_rows = _destination_rows(config, "discord") + _destination_rows(config, "generic")
            configured_keys = {f"{row['kind']}:{row['id']}" for row in configured_rows}
            requested = set(str(value) for value in (record.get("webhook_pending") or []))
            active = requested & configured_keys
            # Removing or disabling a destination cancels its outstanding work;
            # a deleted secret must never be retained in retry state.
            record["webhook_pending"] = sorted(active)
            if active:
                try:
                    webhook_results = webhook_dispatcher(
                        config,
                        payload.get("subject", ""),
                        payload.get("body", ""),
                        payload.get("event", ""),
                        payload.get("severity", ""),
                        payload.get("fields") or [],
                        payload.get("timestamp", ""),
                        normalized_source,
                        payload.get("event_id", ""),
                        payload.get("details") or {},
                        live=True,
                        test=False,
                        dry_run=False,
                        destination_keys=active,
                    )
                except Exception:
                    webhook_results = []
                accepted = {
                    f"{result.get('type')}:{result.get('id')}"
                    for result in webhook_results
                    if result.get("status") == "accepted"
                }
                record["webhook_pending"] = sorted(active - accepted)
                for result in webhook_results:
                    safe_results.append({"delivery": delivery_key, **result})
                if not webhook_results:
                    safe_results.append({
                        "delivery": delivery_key,
                        "type": "webhook",
                        "id": "pending",
                        "status": "failed",
                        "error": "dispatcher_failed",
                    })
                _write_retry_state(locked_path, state)

            if not record.get("email_pending") and not record.get("webhook_pending"):
                record["completed_at"] = int(time.time())
                _write_retry_state(locked_path, state)
        pending = sum(
            1
            for record in state["deliveries"].values()
            if isinstance(record, dict)
            and int(record.get("completed_at", 0) or 0) == 0
            and str((record.get("payload") or {}).get("source") or "").lower() == normalized_source
        )
    return {"results": safe_results, "pending": pending}


def dispatch_discord_destinations(
    config,
    subject,
    body,
    event="",
    severity="",
    fields=None,
    timestamp="",
    source="",
    *,
    live=False,
    test=False,
    dry_run=False,
    event_id="",
    transport=_request_once,
    resolver=socket.getaddrinfo,
    sleep=time.sleep,
    budget_seconds=DEFAULT_DELIVERY_BUDGET,
    clock=time.monotonic,
    wall_clock=time.time,
    enforce_wall_clock=True,
):
    """Compatibility dispatcher for callers that intentionally want Discord only."""
    normalized_source = str(source or "").strip().lower()
    if not live or test or dry_run or normalized_source not in {"nws", "weather.gov", "xweather"}:
        return []
    discord_only = dict(config)
    discord_only["generic_webhooks"] = []
    return dispatch_webhook_destinations(
        discord_only,
        subject,
        body,
        event,
        severity,
        fields,
        timestamp,
        normalized_source,
        event_id,
        None,
        live=True,
        test=False,
        dry_run=False,
        transport=transport,
        resolver=resolver,
        sleep=sleep,
        budget_seconds=budget_seconds,
        clock=clock,
        wall_clock=wall_clock,
        enforce_wall_clock=enforce_wall_clock,
    )


def _env_fields():
    names = ("Type", "Event", "Severity", "Zone", "Radius", "Recipients", "Audio", "Trigger")
    return [(name, os.environ.get("SLS_DESTINATION_" + name.upper(), "")) for name in names]


def _env_details():
    return {
        "zone": os.environ.get("SLS_DESTINATION_ZONE", ""),
        "recipients": os.environ.get("SLS_DESTINATION_RECIPIENTS", ""),
        "audio": os.environ.get("SLS_DESTINATION_AUDIO", ""),
        "audio_sequence": os.environ.get("SLS_DESTINATION_AUDIO_SEQUENCE", ""),
        "message_type": os.environ.get("SLS_DESTINATION_MESSAGE_TYPE", ""),
        "trigger": os.environ.get("SLS_DESTINATION_TRIGGER", ""),
        "trigger_extension": os.environ.get("SLS_DESTINATION_TRIGGER_EXTENSION", ""),
        "radius_miles": os.environ.get("SLS_DESTINATION_RADIUS", ""),
        "nearest_strike_miles": os.environ.get("SLS_DESTINATION_NEAREST", ""),
    }


def main():
    arguments = list(sys.argv[1:])
    config_path = Path(arguments.pop(0)) if arguments and not arguments[0].startswith("--") else DEFAULT_CONFIG
    retry_mode = ""
    retry_state_path = None
    announcement_mode = False
    if arguments:
        if arguments == ["--announcement"]:
            announcement_mode = True
        elif len(arguments) == 2 and arguments[0] in {"--retry-state", "--recorded"}:
            retry_mode, retry_state_path = arguments[0], Path(arguments[1])
        else:
            print("Usage: sls_notification_destinations.py [config] [--announcement|--retry-state state|--recorded state]", file=sys.stderr)
            return 2
    with config_path.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    is_test = os.environ.get("SLS_NOTIFICATION_TEST", "0") == "1"
    is_dry_run = os.environ.get("SLS_NOTIFICATION_DRY_RUN", "0") == "1"
    is_live = os.environ.get("SLS_NOTIFICATION_LIVE", "0") == "1"
    source = os.environ.get("SLS_DESTINATION_SOURCE", "")
    correlation_key = os.environ.get("SLS_EXTERNAL_CORRELATION_KEY", "")
    if announcement_mode:
        destination_ids = [
            value
            for value in os.environ.get("SLS_DESTINATION_IDS", "").split(",")
            if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", value)
        ]
        fields = []
        try:
            raw_fields = json.loads(os.environ.get("SLS_DESTINATION_FIELDS_JSON", "[]"))
            if isinstance(raw_fields, list):
                fields = [
                    (str(entry[0]), str(entry[1]))
                    for entry in raw_fields[:6]
                    if isinstance(entry, list) and len(entry) == 2
                ]
        except (TypeError, ValueError, json.JSONDecodeError):
            fields = []
        results = dispatch_announcement_webhooks(
            config,
            os.environ.get("SLS_DESTINATION_SUBJECT", "Announcement"),
            os.environ.get("SLS_DESTINATION_BODY", ""),
            os.environ.get("SLS_DESTINATION_COLOR", "#6d28d9"),
            fields,
            os.environ.get("SLS_DESTINATION_TIME", ""),
            os.environ.get("SLS_DESTINATION_EVENT_ID", ""),
            destination_ids,
            source=source,
            live=is_live,
            test=is_test,
            dry_run=is_dry_run,
            budget_seconds=os.environ.get("SLS_DESTINATION_BUDGET_SECONDS", DEFAULT_DELIVERY_BUDGET),
        )
        print(json.dumps({"results": results}, separators=(",", ":"), ensure_ascii=True))
        return 1 if len(results) != len(set(destination_ids)) or any(result["status"] != "accepted" for result in results) else 0
    if retry_mode == "--recorded":
        try:
            recorded = external_delivery_recorded(retry_state_path, source, correlation_key)
        except RetryStateError as exc:
            print(json.dumps({"recorded": False, "error": str(exc)}, separators=(",", ":")))
            return 75
        print(json.dumps({"recorded": recorded}, separators=(",", ":")))
        return 0 if recorded else 1
    if retry_mode == "--retry-state":
        if not is_live or is_test or is_dry_run or str(source).strip().lower() not in {"nws", "weather.gov", "xweather"}:
            print('{"results":[],"pending":0}')
            return 0
        try:
            if os.environ.get("SLS_EXTERNAL_RETRY_ONLY", "0") != "1":
                queue_external_delivery(
                    retry_state_path,
                    config,
                    correlation_key,
                    os.environ.get("SLS_DESTINATION_SUBJECT", "Southland Servers Mass Notification"),
                    os.environ.get("SLS_DESTINATION_BODY", "A notification was issued."),
                    os.environ.get("SLS_DESTINATION_EVENT", ""),
                    os.environ.get("SLS_DESTINATION_SEVERITY", ""),
                    _env_fields(),
                    os.environ.get("SLS_DESTINATION_TIME", ""),
                    source,
                    os.environ.get("SLS_DESTINATION_EVENT_ID", ""),
                    _env_details(),
                    os.environ.get("SLS_EMAIL_RECIPIENTS", ""),
                )
            outcome = retry_external_deliveries(
                retry_state_path,
                config,
                source,
                live=True,
                test=False,
                dry_run=False,
                preferred_correlation_key=correlation_key,
            )
            current_pending = (
                external_delivery_pending(retry_state_path, source, correlation_key)
                if correlation_key and os.environ.get("SLS_EXTERNAL_RETRY_ONLY", "0") != "1"
                else None
            )
        except RetryStateError as exc:
            print(json.dumps({"results": [], "pending": -1, "error": str(exc)}, separators=(",", ":")))
            return 75
        if current_pending is not None:
            outcome["current_pending"] = current_pending
        print(json.dumps(outcome, separators=(",", ":"), ensure_ascii=True))
        return 1 if (current_pending if current_pending is not None else outcome["pending"] > 0) else 0

    requested_budget = os.environ.get("SLS_DESTINATION_BUDGET_SECONDS", DEFAULT_DELIVERY_BUDGET)
    results = dispatch_webhook_destinations(
        config,
        os.environ.get("SLS_DESTINATION_SUBJECT", "Southland Servers Mass Notification"),
        os.environ.get("SLS_DESTINATION_BODY", "A notification was issued."),
        os.environ.get("SLS_DESTINATION_EVENT", ""),
        os.environ.get("SLS_DESTINATION_SEVERITY", ""),
        _env_fields(),
        os.environ.get("SLS_DESTINATION_TIME", ""),
        source,
        os.environ.get("SLS_DESTINATION_EVENT_ID", ""),
        _env_details(),
        live=is_live,
        test=is_test,
        dry_run=is_dry_run,
        budget_seconds=requested_budget,
    )
    print(json.dumps({"results": results}, separators=(",", ":"), ensure_ascii=True))
    return 1 if any(result["status"] != "accepted" for result in results) else 0


if __name__ == "__main__":
    raise SystemExit(main())
