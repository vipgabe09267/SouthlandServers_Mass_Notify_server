#!/usr/bin/env python3
"""Deliver live alerts to Discord, Slack, Teams Workflows and HTTPS receivers.

The dispatcher deliberately requires an explicit live-delivery flag.  Manual
tests, dry runs, previews, and direct CLI invocation cannot send webhooks unless
the caller also identifies a live NWS/Xweather alert or a Dashboard announcement
with explicitly selected, frozen destinations.
"""

import importlib.util
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
import subprocess
import sys
import tempfile
import threading
import time
from contextlib import contextmanager, nullcontext
from datetime import datetime, timezone
from pathlib import Path

import sys as _config_sys
_config_sys.dont_write_bytecode = True
_config_crypto_spec = importlib.util.spec_from_file_location("sls_config_crypto", Path(__file__).resolve().with_name("sls_config_crypto.py"))
_config_crypto = importlib.util.module_from_spec(_config_crypto_spec)
_config_crypto_spec.loader.exec_module(_config_crypto)
_cluster_spec = importlib.util.spec_from_file_location('sls_cluster_guard', Path(__file__).resolve().with_name('sls_cluster_guard.py'))
_cluster_guard = importlib.util.module_from_spec(_cluster_spec)
_cluster_spec.loader.exec_module(_cluster_guard)
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
USER_AGENT = "SouthlandServers-Mass-Notifications-Server/0.1.5-beta"
DISCORD_HOSTS = {"discord.com", "discordapp.com", "canary.discord.com", "ptb.discord.com"}
DISCORD_PATH = re.compile(r"^/api/webhooks/[0-9]+/[A-Za-z0-9._~-]+$")
# Downloaded and bundled artwork; providers retrieve it from this PBX, not
# from the Southland Servers website. The revision matches the packaged PNG.
DISCORD_EMBED_IMAGE_PATH = "/sls_mass_notify/assets/webhook-builder.png?v=b9e17309980e"
DNS_NAME = re.compile(
    r"^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+"
    r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$",
    re.IGNORECASE,
)


class DestinationError(RuntimeError):
    """A destination failure whose message is safe to expose in local logs."""

    def __init__(self, code, *, submission_possible=None):
        super().__init__(str(code))
        self.code = str(code)
        # A legacy/custom transport does not prove that a POST was never sent.
        # Only validation/DNS failures are intrinsically pre-submission; the
        # built-in transport records its connect-versus-request phase explicitly.
        if submission_possible is None:
            submission_possible = self.code not in {
                "invalid_url", "invalid_discord_url", "private_address_blocked",
                "ip_literal_blocked", "invalid_hostname",
                "dns_failure", "dns_invalid_address", "dns_no_addresses",
                "payload_too_large", "invalid_webhook_authentication", "invalid_payload_format",
            }
        self.submission_possible = bool(submission_possible)


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
    """Return the bundled image on the configured public HTTPS origin."""
    if not isinstance(config, dict):
        return ""
    sip = config.get("sipnotify")
    host = config.get("public_pbx_host", sip.get("pbx_host", "") if isinstance(sip, dict) else "")
    if not isinstance(host, str) or not DNS_NAME.fullmatch(host) or host.endswith("."):
        return ""
    api = config.get("control_api")
    base = api.get("base_url", "") if isinstance(api, dict) else ""
    port = 443
    if base:
        if not isinstance(base, str):
            return ""
        match = re.fullmatch(r"https://" + re.escape(host) + r"(?::([0-9]{1,5}))?/api/sls-mass-notify/?", base, re.I)
        if not match:
            return ""
        port = int(match[1] or 443)
        if not 1 <= port <= 65535:
            return ""
    origin = "https://" + host.lower() + (":" + str(port) if port != 443 else "")
    return origin + DISCORD_EMBED_IMAGE_PATH


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
        },
        "timestamp": _normalize_timestamp(timestamp),
    }
    if avatar_url:
        embed["footer"]["icon_url"] = avatar_url
    payload = {
        "username": "SLS Mass Notification System",
        "embeds": [embed],
    }
    if avatar_url:
        payload["avatar_url"] = avatar_url
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


def destination_fingerprint(row):
    """Bind queued delivery to its receiver and wire format; retain native hashes."""
    adapter = row.get("payload_format", "native")
    material = row["url"]
    if adapter != "native":
        material += "\npayload_format=" + str(adapter)
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def _integration_text(value):
    # Keep the complete alert, including paragraphs, instead of silently clipping
    # safety instructions to fit a provider. Oversized messages fail before POST.
    return re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", str(value or "")).strip()


def incident_operator_link(config, incident_id):
    """Link only a typed incident to the configured PBX, never alert text/headers."""
    if not isinstance(incident_id, str) or not re.fullmatch(r"inc_[a-f0-9]{32}", incident_id):
        raise DestinationError("invalid_incident_context", submission_possible=False)
    config = config if isinstance(config, dict) else {}
    api = config.get("control_api")
    sip = config.get("sipnotify")
    host = config.get("public_pbx_host", sip.get("pbx_host", "") if isinstance(sip, dict) else "")
    base = api.get("base_url", "") if isinstance(api, dict) else ""
    if not isinstance(host, str) or len(host) > 253 or not re.fullmatch(
            r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", host, re.I):
        return ""
    if not isinstance(base, str):
        return ""
    match = re.fullmatch(r"https://" + re.escape(host) + r"(?::([0-9]{1,5}))?/api/sls-mass-notify/?", base, re.I)
    if not match:
        return ""
    port = int(match[1] or 443)
    if not 1 <= port <= 65535:
        return ""
    origin = "https://" + host.lower() + (":" + str(port) if port != 443 else "")
    return origin + "/admin/config.php?display=slsmassnotifyserver_incidents&incident_id=" + incident_id


def collaboration_incident_context(value):
    if value is None:
        return None
    if (not isinstance(value, dict) or set(value) != {'schema', 'incident_id', 'sequence', 'kind', 'severity', 'is_test'}
            or type(value['schema']) is not int or value['schema'] != 1
            or not isinstance(value['incident_id'], str) or not re.fullmatch(r'inc_[a-f0-9]{32}', value['incident_id'])
            or type(value['sequence']) is not int or not 1 <= value['sequence'] <= 250
            or value['kind'] not in ('initial', 'update', 'all_clear', 'escalation')
            or value['severity'] not in ('information', 'warning', 'critical') or type(value['is_test']) is not bool):
        raise DestinationError('invalid_incident_context', submission_possible=False)
    return dict(value)


def build_collaboration_payload(adapter, title, body, event="", severity="", fields=None,
                                timestamp="", source="", event_id="", *, incident_id="", config=None, incident_context=None):
    if not isinstance(adapter, str) or adapter not in {"slack", "teams_workflow"}:
        raise DestinationError("invalid_payload_format", submission_possible=False)
    title = _integration_text(title) or "SLS Mass Notification"
    body = _integration_text(body)
    incident_context = collaboration_incident_context(incident_context)
    if incident_context is not None:
        if incident_id not in ('', incident_context['incident_id']):
            raise DestinationError('invalid_incident_context', submission_possible=False)
        incident_id = incident_context['incident_id']
        event = {'initial':'Incident', 'update':'Incident update', 'all_clear':'Incident all-clear', 'escalation':'Incident escalation'}[incident_context['kind']]
        severity = incident_context['severity'].capitalize()
    metadata = [("Event", event), ("Severity", severity), ("Source", source)]
    metadata.extend(list(fields or [])[:6])
    metadata.extend([("Event ID", event_id), ("Time", timestamp)])
    incident_url = ""
    if incident_id != "":
        incident_url = incident_operator_link(config, incident_id)
        metadata.append(("Incident ID", incident_id))
    if incident_context is not None:
        metadata.extend([('Incident sequence', str(incident_context['sequence'])), ('Drill / test', 'Yes' if incident_context['is_test'] else 'No')])
    lines = [title, body] + [f"{_integration_text(name)}: {_integration_text(value)}"
                            for name, value in metadata if _integration_text(value)]
    text = "\n\n".join(part for part in lines if part)
    # Teams posting actions have an approximately 28 KiB limit. Leave room for
    # workflow/host wrapping, and apply the same conservative limit to Slack.
    if len(text.encode("utf-8")) > 24 * 1024:
        raise DestinationError("payload_too_large", submission_possible=False)
    if adapter == "slack":
        # Block Kit plain_text never interprets alert content as mentions or
        # Markdown. Escape the notification fallback and disable auto parsing.
        fallback = title.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
        payload = {"text": fallback, "mrkdwn": False, "parse": "none", "link_names": False,
                   "unfurl_links": False, "unfurl_media": False,
                   "blocks": [{"type": "section", "text": {"type": "plain_text", "text": text[i:i + 3000], "emoji": False}}
                              for i in range(0, len(text), 3000)]}
        if incident_url:
            # A normal link needs no interaction callback. Only this fixed label
            # and a validated configured origin enter mrkdwn; alert text cannot.
            payload["blocks"].append({"type": "context", "elements": [{"type": "mrkdwn",
                "text": "<" + incident_url.replace("&", "&amp;") + "|Open incident in SLS>", "verbatim": True}]})
    else:
        # TextRun is plain text (no Markdown). The only action opens the protected
        # PBX incident page; it never submits a human response or changes state.
        card = {"$schema": "http://adaptivecards.io/schemas/adaptive-card.json",
                "type": "AdaptiveCard", "version": "1.2",
                "body": [{"type": "RichTextBlock", "inlines": [{"type": "TextRun", "text": part}]} for part in lines if part]}
        if incident_url:
            card["actions"] = [{"type": "Action.OpenUrl", "title": "Open incident in SLS", "url": incident_url}]
        payload = {"type": "message", "attachments": [{"contentType": "application/vnd.microsoft.card.adaptive", "contentUrl": None, "content": card}]}
    if len(json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")) > 24 * 1024:
        raise DestinationError("payload_too_large", submission_possible=False)
    return payload


def _row_payload(row, native_payload, title, body, event, severity, fields, timestamp, source, event_id,
                 *, incident_id="", config=None, incident_context=None):
    adapter = row.get("payload_format", "native")
    if adapter == "native":
        return native_payload
    if row["kind"] == "discord":
        raise DestinationError("invalid_payload_format", submission_possible=False)
    return build_collaboration_payload(adapter, title, body, event, severity, fields, timestamp, source, event_id,
                                       incident_id=incident_id, config=config, incident_context=incident_context)


def _payload_failure(row, code):
    return {"type": row["kind"], "id": row["id"], "name": row["name"], "status": "failed",
            "attempts": 0, "http_status": None, "error": code, "retryable": False}


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


class _AnnouncementSubmissionGuard:
    def __init__(self, deadline, authorization):
        self.deadline = deadline
        self.authorization = authorization
        self.failure_code = 'schedule_deadline_expired'

    def __call__(self):
        if self.deadline is not None and not self.deadline():
            self.failure_code = 'schedule_deadline_expired'
            return False
        if self.authorization is not None and not self.authorization():
            self.failure_code = 'authorization_changed'
            return False
        return True


def _current_authorization(context):
    """Use the same PHP policy as admission; no framework bootstrap or writes."""
    encoded = json.dumps(context, separators=(',', ':')).encode('utf-8')
    if len(encoded) > 16384:
        return False
    try:
        result = subprocess.run(['/usr/bin/php', '/usr/local/bin/sls_mass_notify/sls_mass_notify_delivery_authorization.php'],
                                input=encoded, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=1, check=False, env={'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'})
        return result.returncode == 0 and len(result.stdout) < 256 and json.loads(result.stdout) == {'allowed': True}
    except (OSError, subprocess.TimeoutExpired, ValueError):
        return False


def _request_once(url, kind, payload, timeout=DEFAULT_TIMEOUT, resolver=socket.getaddrinfo, idempotency_key="", authentication=None,
                  submission_guard=None):
    request_deadline = time.monotonic() + max(0.1, float(timeout))
    try:
        hostname, path, addresses = _validated_url(url, kind, resolver)
    except DeliveryBudgetExceeded as exc:
        raise DestinationError("delivery_budget_exhausted", submission_possible=False) from exc
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
        if submission_guard is not None and not submission_guard():
            raise DestinationError(getattr(submission_guard, 'failure_code', 'schedule_deadline_expired'), submission_possible=False)
        remaining = request_deadline - time.monotonic()
        if remaining <= 0:
            raise DestinationError("request_timeout", submission_possible=False)
        connection = _PinnedHTTPSConnection(hostname, address, remaining)
        submission_possible = False
        cluster_claim = None
        try:
            # Complete DNS/TCP/TLS before transmission.  Once request() starts,
            # even an exception from send() can follow a partially sent body.
            connection.connect()
            remaining = request_deadline - time.monotonic()
            if remaining <= 0:
                raise DestinationError("request_timeout", submission_possible=False)
            if connection.sock is not None:
                connection.sock.settimeout(remaining)
            # DNS and TLS can outlast a schedule window. Check again at the
            # transmission boundary; a POST already started may finish normally.
            if submission_guard is not None and not submission_guard():
                raise DestinationError(getattr(submission_guard, 'failure_code', 'schedule_deadline_expired'), submission_possible=False)
            timestamp = payload.get('timestamp') or ((payload.get('embeds') or [{}])[0].get('timestamp', ''))
            created_at = _timestamp(timestamp)
            cluster_claim = _cluster_guard.begin('webhook', hashlib.sha256(url.encode()).hexdigest(), payload,
                                                 delivery_id='webhook-' + hashlib.sha256(idempotency_key.encode()).hexdigest(),
                                                 created_at=created_at)
            submission_possible = True
            connection.request("POST", path, body=body, headers=headers)
            remaining = request_deadline - time.monotonic()
            if remaining <= 0:
                raise DestinationError("request_timeout", submission_possible=True)
            if connection.sock is not None:
                connection.sock.settimeout(remaining)
            response = connection.getresponse()
            status = int(response.status)
            _cluster_guard.finish(cluster_claim, uncertain=False, category='http_response_' + str(status))
            # The status is sufficient.  Waiting for a response body can lose a
            # known acceptance to a timeout, and no response content is logged.
            return status, response.getheader("Retry-After", "")
        except DeliveryBudgetExceeded as exc:
            raise DestinationError("delivery_budget_exhausted", submission_possible=submission_possible) from exc
        except DestinationError:
            raise
        except _cluster_guard.ClusterFenced as exc:
            raise DestinationError('cluster_authority_unavailable', submission_possible=submission_possible) from exc
        except ssl.SSLError as exc:
            last_category = "tls_failure"
            if submission_possible:
                raise DestinationError(last_category, submission_possible=True) from exc
        except (TimeoutError, socket.timeout) as exc:
            last_category = "request_timeout"
            if submission_possible:
                raise DestinationError(last_category, submission_possible=True) from exc
        except (OSError, http.client.HTTPException) as exc:
            last_category = "network_failure"
            if submission_possible:
                raise DestinationError(last_category, submission_possible=True) from exc
        finally:
            if cluster_claim is not None and submission_possible:
                # finish is idempotent for the same immutable receipt. The
                # witness keeps uncertainty if no response was received.
                if 'status' not in locals():
                    try:
                        _cluster_guard.finish(cluster_claim, uncertain=True, category='webhook_reply_uncertain')
                    except Exception:
                        pass
            connection.close()
    raise DestinationError(last_category, submission_possible=False)


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
            "payload_format": row.get("payload_format", "native"),
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
            "payload_format": row.get("payload_format", "native"),
            "authentication": {key: row.get(key, '') for key in ('bearer_token', 'signing_secret')} if hostname not in DISCORD_HOSTS else {},
        })
    return output


def configured_external_destination_keys(config):
    return [f"{row['kind']}:{row['id']}" for row in _destination_rows(config, "discord") + _destination_rows(config, "generic")]


def external_destination_fingerprints(config):
    return {f"{row['kind']}:{row['id']}": destination_fingerprint(row)
            for row in _destination_rows(config, "discord") + _destination_rows(config, "generic")}


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


def _uncertain_delivery(row, attempts=1, reason="transport_failure", http_status=None):
    # Never expose exception text, URLs, response bodies, or arbitrary transport
    # error codes through a receipt.
    safe_reasons = {
        "network_failure", "tls_failure", "request_timeout",
        "delivery_budget_exhausted", "http_failure", "transport_failure",
        "dispatcher_failed", "interrupted_submission",
    }
    return {
        "type": row["kind"], "id": row["id"], "name": row["name"],
        "status": "uncertain", "attempts": max(0, int(attempts)),
        "http_status": http_status, "error": "delivery_unconfirmed",
        "failure_reason": reason if reason in safe_reasons else "transport_failure",
    }


def _deliver(row, payload, event_id, transport, resolver, sleep, deadline, clock, submission_guard=None):
    safe = {"type": row["kind"], "id": row["id"], "name": row["name"]}
    for attempt in range(1, MAX_ATTEMPTS + 1):
        if submission_guard is not None and not submission_guard():
            code = getattr(submission_guard, 'failure_code', 'schedule_deadline_expired')
            return {**safe, "status": "cancelled" if code == 'authorization_changed' else "failed", "attempts": attempt - 1, "http_status": None,
                    "error": code, "retryable": False}
        remaining = deadline - clock()
        if remaining <= 0:
            return _budget_failure(row, attempt - 1)
        request_timeout = min(DEFAULT_TIMEOUT, remaining)
        try:
            auth = row.get('authentication', {}) if row.get('payload_format', 'native') == 'native' else {}
            kwargs = {'authentication': auth} if any(auth.values()) else {}
            if submission_guard is not None:
                kwargs['submission_guard'] = submission_guard
            status, retry_after = transport(row["url"], row["kind"], payload, request_timeout, resolver, event_id, **kwargs)
        except DeliveryBudgetExceeded:
            return _uncertain_delivery(row, attempt, "delivery_budget_exhausted")
        except DestinationError as exc:
            if exc.submission_possible:
                return _uncertain_delivery(row, attempt, exc.code)
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
            return {**safe, "status": "cancelled" if exc.code == 'authorization_changed' else "failed", "attempts": attempt, "http_status": None, "error": exc.code,
                    **({'retryable': False} if exc.code in {'schedule_deadline_expired', 'authorization_changed'} else {})}
        except Exception:
            return _uncertain_delivery(row, attempt)
        if 200 <= status < 300:
            return {**safe, "status": "accepted", "attempts": attempt, "http_status": status, "error": ""}
        # An HTTP timeout/server error can follow successful side effects.  An
        # Idempotency-Key header alone is not a receiver deduplication contract.
        if status == 408 or 500 <= status <= 599:
            return _uncertain_delivery(row, attempt, "http_failure", status)
        if status in {425, 429}:
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
        try:
            payload = _row_payload(row, discord_payload if row["kind"] == "discord" else generic_payload,
                                   subject, body, event, severity, fields, normalized_timestamp, normalized_source, normalized_event_id)
        except DestinationError as exc:
            results.append(_payload_failure(row, exc.code))
            continue
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
            result = _uncertain_delivery(row, reason="delivery_budget_exhausted")
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
    expected_fingerprints=None,
    latest_start=None,
    incident_id="",
    incident_context=None,
    authorization_guard=None,
):
    """Deliver a real Dashboard announcement to explicitly selected destinations."""
    if not live or test or dry_run or str(source or "").strip().lower() != "dashboard":
        return []
    if latest_start is not None and (type(latest_start) is not int or not 0 < latest_start <= 253402300799):
        raise DestinationError('invalid_schedule_deadline', submission_possible=False)
    latest_start_tick = None if latest_start is None else clock() + max(0, latest_start - wall_clock())
    deadline_guard = None if latest_start is None else lambda: wall_clock() < latest_start and clock() < latest_start_tick
    submission_guard = _AnnouncementSubmissionGuard(deadline_guard, authorization_guard) if deadline_guard is not None or authorization_guard is not None else None
    selected = {
        str(value)
        for value in (destination_ids or [])
        if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", str(value))
    }
    if not selected:
        return []
    rows = [row for row in _announcement_destination_rows(config) if row["id"] in selected]
    expected_fingerprints = expected_fingerprints if isinstance(expected_fingerprints, dict) else {}
    results = []
    valid_rows = []
    by_id = {row["id"]: row for row in rows}
    for destination_id in sorted(selected):
        row = by_id.get(destination_id)
        expected = expected_fingerprints.get(destination_id)
        if not isinstance(expected, str) or not re.fullmatch(r"[a-f0-9]{64}", expected):
            error = "legacy_route_snapshot_missing"
        elif row is None:
            error = "destination_unavailable"
        elif not hmac.compare_digest(expected, destination_fingerprint(row)):
            error = "destination_changed"
        else:
            valid_rows.append(row)
            continue
        identity = {key: row[key] for key in ("kind", "id", "name")} if row else {"kind": "generic", "id": destination_id, "name": destination_id}
        results.append({**identity, "status": "failed" if error == "legacy_route_snapshot_missing" else "cancelled",
                        "attempts": 0, "http_status": None, "error": error, "retryable": False})
    # Validate the URL from this process's current config, then submit that exact
    # row. A URL edit between the PHP snapshot/check and this load cannot retarget
    # a queued message. Only nonsecret hashes cross the process boundary.
    rows = valid_rows
    if not rows:
        return results
    budget = _effective_delivery_budget(budget_seconds, wall_clock)
    normalized_timestamp = _normalize_timestamp(timestamp)
    normalized_event_id = _normalized_event_id(
        event_id,
        "dashboard",
        title,
        "announcement",
        normalized_timestamp,
    )
    native_payload = build_announcement_discord_payload(
        config,
        title,
        body,
        background_color,
        fields,
        normalized_timestamp,
    )
    if len(json.dumps(native_payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")) > MAX_PAYLOAD_BYTES:
        return results + [
            {**{key: row[key] for key in ("kind", "id", "name")}, "status": "failed", "attempts": 0,
             "http_status": None, "error": "payload_too_large"}
            for row in rows
        ]
    if budget <= 0:
        return results + [_budget_failure(row) for row in rows]
    deadline = clock() + budget
    for index, row in enumerate(rows):
        if submission_guard is not None and not submission_guard():
            code = getattr(submission_guard, 'failure_code', 'schedule_deadline_expired')
            failures = [_payload_failure(pending, code) for pending in rows[index:]]
            if code == 'authorization_changed':
                for failure in failures:
                    failure.update(status='cancelled', retryable=False)
            results.extend(failures)
            break
        remaining = deadline - clock()
        if remaining <= 0:
            results.extend(_budget_failure(pending) for pending in rows[index:])
            break
        rows_left = len(rows) - index
        row_budget = remaining / rows_left
        row_deadline = clock() + row_budget
        try:
            payload = _row_payload(row, native_payload, title, body, "Announcement", "Information", fields,
                                   normalized_timestamp, "dashboard", normalized_event_id,
                                   incident_id=incident_id, config=config, incident_context=incident_context)
        except DestinationError as exc:
            results.append(_payload_failure(row, exc.code))
            continue
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
                    submission_guard,
                )
        except DeliveryBudgetExceeded:
            result = _uncertain_delivery(row, reason="delivery_budget_exhausted")
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
            record.update(completed_at=current, terminal_status='expired', email_pending=False, webhook_pending=[], channels_pending=False)
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


def _record_external_receipt(record, result):
    """Retain bounded channel evidence independently of runnable retry flags."""
    kind, identifier = result.get("type"), result.get("id")
    if kind not in {"email", "discord", "generic", "channels"} or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", str(identifier)):
        raise RetryStateError("external_receipt_invalid")
    status = result.get("status")
    if status not in {"accepted", "failed", "uncertain", "cancelled", "queued", "deferred"}:
        raise RetryStateError("external_receipt_invalid")
    receipts = record.setdefault("destination_receipts", {})
    if not isinstance(receipts, dict) or len(receipts) > MAX_DESTINATIONS * 2 + 1:
        raise RetryStateError("external_receipt_capacity")
    key = kind + ":" + identifier
    error = result.get("error", "")
    safe_error = error if isinstance(error, str) and re.fullmatch(r"(?:[a-z0-9_]{1,80})?", error) else "transport_failure"
    http_status = result.get("http_status")
    receipts[key] = {"status": status, "error": safe_error, "recorded_at": int(time.time()),
                     "http_status": http_status if type(http_status) is int and 100 <= http_status <= 599 else None,
                     "attempt_sequence": record.get("last_attempt_sequence", 0)}


def _timestamp(value):
    try:
        parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        return int(parsed.timestamp()) if parsed.tzinfo is not None else 0
    except (TypeError, ValueError, OverflowError):
        return 0


def weather_source_validity(provider, group_id, *, feature=None, chain_key="", zone="", observed_at=None,
                            event_kind="", cluster_started=0, configuration_identity=""):
    """Create only bounded source identity; no paths or credentials are persisted."""
    if provider not in {"nws", "xweather"} or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", str(group_id)):
        raise RetryStateError("invalid_source_identity")
    current = int(time.time() if observed_at is None else observed_at)
    result = {"version": 1, "provider": provider, "group_id": group_id, "observed_at": current}
    if provider == "nws":
        properties = feature.get("properties", {}) if isinstance(feature, dict) else {}
        identifier = str(feature.get("id") or properties.get("id") or "") if isinstance(feature, dict) else ""
        expires = _timestamp(properties.get("expires"))
        if not identifier or len(identifier) > 2048 or not chain_key or len(chain_key) > 1024 or expires <= 0 or not re.fullmatch(r"[A-Z]{2}[CZ][0-9]{3}", zone):
            raise RetryStateError("invalid_source_identity")
        result.update(provider_event_id=identifier, chain_key=chain_key, expires_at=expires, zone=zone)
    else:
        if event_kind not in {"entry", "clear"} or int(cluster_started) <= 0 or not re.fullmatch(r"[a-f0-9]{64}", configuration_identity):
            raise RetryStateError("invalid_source_identity")
        result.update(event_kind=event_kind, cluster_started=int(cluster_started),
                      configuration_identity=configuration_identity,
                      expires_at=current + PENDING_RETRY_MAX_AGE_SECONDS)
    return result


def _read_weather_observation(path, maximum=16 * 1024 * 1024):
    descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0))
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > maximum:
            raise ValueError("invalid_observation")
        with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
            descriptor = -1
            value = json.load(handle)
        if not isinstance(value, dict):
            raise ValueError("invalid_observation")
        return value
    finally:
        if descriptor >= 0:
            os.close(descriptor)


def _source_group(config, validity):
    if validity["provider"] == "xweather":
        # Reuse the producer's legacy-area normalization and identity semantics.
        from sls_mass_notify_xweather_poll import configured_groups
        root = config.get("xweather") or {}
        if not _enabled(root.get("enabled", "0")):
            return None
        return next((row for row in configured_groups(root) if row["id"] == validity["group_id"]), None)
    if not _enabled(config.get("enabled", "0")):
        return None
    rows = config.get("nws_zones") or [{"name": "Primary Weather Zone", "zone": config.get("nws_zone", "")}]
    for raw in rows[:5]:
        if not isinstance(raw, dict):
            continue
        row = {**config, **raw}
        zone = str(raw.get("zone") or "").strip().upper()
        name = re.sub(r"\s+", " ", str(raw.get("name") or zone)).strip()[:64]
        group_id = re.sub(r"[^A-Za-z0-9_-]", "", str(raw.get("id") or ""))[:64]
        group_id = group_id or "nws_" + hashlib.sha256(f"{name.lower()}|{zone}".encode()).hexdigest()[:12]
        if group_id == validity["group_id"] and _enabled(raw.get("enabled", "1")):
            row["zone"] = zone
            return row
    return None


def _quiet_now(group, now):
    if not _enabled(group.get("quiet_hours_enabled", "0")):
        return False
    values = []
    for name, fallback in (("quiet_hours_start", "21:00"), ("quiet_hours_end", "06:00")):
        value = str(group.get(name, fallback))
        if not re.fullmatch(r"(?:[01][0-9]|2[0-3]):[0-5][0-9]", value):
            return True  # Invalid policy must not silently allow a delivery.
        hour, minute = map(int, value.split(":"))
        values.append(hour * 60 + minute)
    start, end = values
    local = datetime.fromtimestamp(now).astimezone()
    minute = local.hour * 60 + local.minute
    return False if start == end else (start <= minute < end if start < end else minute >= start or minute < end)


def validate_external_weather(record, config, directory, now=None):
    """Recheck current source/policy. Stale observations defer; known invalidity cancels."""
    now = int(time.time() if now is None else now)
    validity = record.get("source_validity")
    # Old records cannot prove source freshness or audience ownership. Never
    # guess a group or send an old alert using a newly configured recipient set.
    if not isinstance(validity, dict) or validity.get("version") != 1:
        return "cancelled", "source_identity_unavailable", None
    if validity.get("provider") not in {"nws", "xweather"} or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", str(validity.get("group_id", ""))):
        return "cancelled", "source_identity_invalid", None
    if now >= _retry_record_integer(validity, "expires_at"):
        return "cancelled", "source_expired", None
    group = _source_group(config, validity)
    if group is None:
        return "cancelled", "source_disabled_or_removed", None
    try:
        if validity["provider"] == "nws":
            snapshot = _read_weather_observation(Path(directory) / "weather-delivery.json").get("snapshots", {}).get(validity["group_id"], {})
            if not 0 <= now - float(snapshot.get("observed_at", 0)) <= 180:
                return "deferred", "source_observation_stale", group
            if snapshot.get("zone") != group.get("zone") or validity.get("zone") != group.get("zone"):
                return "cancelled", "source_area_changed", group
            feature = snapshot.get("active", {}).get(validity.get("chain_key"))
            if not isinstance(feature, dict):
                return "cancelled", "source_cancelled", group
            properties = feature.get("properties", {})
            if properties.get("status") != "Actual" or properties.get("messageType") == "Cancel":
                return "cancelled", "source_cancelled", group
            if _timestamp(properties.get("expires")) <= now:
                return "cancelled", "source_expired", group
            if str(feature.get("id") or properties.get("id") or "") != validity.get("provider_event_id"):
                return "cancelled", "source_superseded", group
            critical = str((record.get("payload") or {}).get("event", "")) in (group.get("quiet_critical_events") or [])
        else:
            from sls_mass_notify_xweather_poll import lightning_area_identity
            if validity.get("configuration_identity") != lightning_area_identity(config, group):
                return "cancelled", "source_area_changed", group
            observation = _read_weather_observation(Path(directory) / f"xweather-lightning-state-{validity['group_id']}.json", 2 * 1024 * 1024)
            interval = max(1, min(10, int(group.get("query_interval_minutes", 5) or 5))) * 60
            if not 0 <= now - float(observation.get("last_observed_at", 0)) <= max(660, 2 * interval + 60):
                return "deferred", "source_observation_stale", group
            if observation.get("cluster_started") != validity.get("cluster_started"):
                return "cancelled", "source_superseded", group
            if validity.get("event_kind") == "entry":
                if not observation.get("active"):
                    return "cancelled", "source_cleared", group
            elif validity.get("event_kind") == "clear":
                if observation.get("active") or group.get("all_clear") != "send":
                    return "cancelled", "source_superseded", group
            else:
                return "cancelled", "source_identity_invalid", group
            # Lightning has no configured critical exemption in the current
            # schema. A warning label alone does not bypass quiet hours.
            critical = False
    except (OSError, ValueError, TypeError, AttributeError):
        return "deferred", "source_observation_unavailable", group
    if _quiet_now(group, now) and not critical:
        return "deferred", "quiet_hours", group
    return "eligible", "", group


def _remaining_external_routes(record, group, config):
    original = str(record.get("email_recipients") or "").split()
    allowed = {str(value).strip().lower() for value in group.get("email_recipients", [])}
    recipients = " ".join(value for value in original if value.lower() in allowed)
    allowed_hooks = {f"{row['kind']}:{row['id']}" for row in _destination_rows(config, "discord") + _destination_rows(config, "generic")}
    original_fingerprints = (record.get("routing_snapshot") or {}).get("webhook_fingerprints") or {}
    current_fingerprints = external_destination_fingerprints(config)
    allowed_hooks = {key for key in allowed_hooks if original_fingerprints.get(key) == current_fingerprints.get(key)}
    if "discord_webhook_ids" in group or "generic_webhook_ids" in group:
        allowed_hooks &= {f"{kind}:{value}" for kind in ("discord", "generic") for value in group.get(kind + "_webhook_ids", [])}
    pending = set(record.get("webhook_pending") or [])
    return recipients, sorted(pending & allowed_hooks)


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
    source_validity=None,
    payload_preparation_error="",
    channel_snapshot=None,
):
    """Durably record external work before a caller commits local dedup state."""
    if payload_preparation_error not in {"", "weather_details_unavailable"}:
        raise RetryStateError("invalid_payload_preparation_error")
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
        group = _source_group(config, source_validity) if isinstance(source_validity, dict) else None
        channels = {'voice_recipient_ids':[], 'sms_recipient_ids':[], 'fingerprints':{}}
        if group and (group.get('voice_recipient_ids') or group.get('sms_recipient_ids')):
            from sls_weather_channels import snapshot
            channels = snapshot(config, group)
        override = os.environ.get('SLS_WEATHER_CHANNEL_SNAPSHOT', '')
        if channel_snapshot is not None or override:
            channels = channel_snapshot if channel_snapshot is not None else json.loads(override)
            if not isinstance(channels, dict) or set(channels) != {'voice_recipient_ids','sms_recipient_ids','fingerprints'}:
                raise RetryStateError('invalid_original_weather_channels')
        channels_pending = bool(channels['voice_recipient_ids'] or channels['sms_recipient_ids'])
        state["deliveries"][delivery_key] = {
            "created_at": current,
            "expires_at": current + PENDING_RETRY_MAX_AGE_SECONDS,
            "completed_at": 0 if pending_email or pending_webhooks or channels_pending else current,
            "last_attempt_at": 0,
            "last_attempt_sequence": 0,
            "payload": _retry_payload(
                subject, body, event, severity, fields, timestamp, source, event_id, details
            ),
            "email_pending": pending_email,
            "email_recipients": recipients,
            "webhook_pending": pending_webhooks,
            "source_validity": source_validity,
            "channels": channels, "channels_pending": channels_pending,
            "routing_snapshot": {"email_recipients": recipients.split(), "webhook_ids": pending_webhooks,
                                 "webhook_fingerprints": {key: value for key, value in external_destination_fingerprints(config).items() if key in pending_webhooks}},
        }
        if payload_preparation_error and (pending_email or pending_webhooks or channels_pending):
            # Persist a terminal rejection before local delivery. An incomplete
            # external message must never be sent or retried, but its rejection
            # must not prevent the independent phone/Desktop submissions.
            record = state["deliveries"][delivery_key]
            targets = (["email:email"] if pending_email else []) + pending_webhooks + (["channels:sms_external_voice"] if channels_pending else [])
            for target in targets:
                kind, identifier = target.split(":", 1)
                _record_external_receipt(record, {"type": kind, "id": identifier,
                    "status": "failed", "error": payload_preparation_error})
            record.update(completed_at=current, terminal_status="failed",
                          email_pending=False, webhook_pending=[], channels_pending=False)
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


def external_delivery_uncertain(state_path, source, correlation_key):
    """Report unresolved submission outcomes without making them retryable."""
    delivery_key = _retry_delivery_key(source, correlation_key)
    with _locked_retry_state(state_path) as locked_path:
        state = _load_retry_state(locked_path)
        record = state["deliveries"].get(delivery_key)
        return isinstance(record, dict) and bool(
            record.get("webhook_uncertain") or record.get("webhook_inflight")
        )


def external_delivery_status(state_path, source, correlation_key):
    delivery_key = _retry_delivery_key(source, correlation_key)
    with _locked_retry_state(state_path) as locked_path:
        record = _load_retry_state(locked_path)["deliveries"].get(delivery_key)
        if not isinstance(record, dict):
            return "unknown"
        if record.get("webhook_uncertain") or record.get("webhook_inflight"):
            return "uncertain"
        if not record.get("completed_at"):
            return "pending"
        return str(record.get("terminal_status") or "complete")


def _recover_webhook_inflight(state):
    """A process exit after durable intent must never automatically replay POSTs."""
    results = []
    for delivery_key, record in state["deliveries"].items():
        if not isinstance(record, dict):
            raise RetryStateError("retry_state_corrupt")
        inflight = record.get("webhook_inflight") or []
        uncertain = record.get("webhook_uncertain") or {}
        pending = record.get("webhook_pending") or []
        if not isinstance(inflight, list) or not isinstance(uncertain, dict) or not isinstance(pending, list):
            raise RetryStateError("retry_state_corrupt")
        for key in inflight:
            if not isinstance(key, str) or not re.fullmatch(r"(?:discord|generic):[A-Za-z0-9_-]{1,64}", key):
                raise RetryStateError("retry_state_corrupt")
            kind, identifier = key.split(":", 1)
            receipt = _uncertain_delivery(
                {"kind": kind, "id": identifier, "name": identifier},
                reason="interrupted_submission",
            )
            uncertain[key] = receipt
            results.append({"delivery": delivery_key, **receipt})
        if inflight:
            record["webhook_inflight"] = []
            record["webhook_uncertain"] = uncertain
            record["webhook_pending"] = sorted(set(pending) - set(inflight))
            if not record.get("email_pending") and not record["webhook_pending"] and not record.get("channels_pending"):
                record.update(completed_at=int(time.time()), terminal_status="uncertain")
    return results


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
    validity_checker=None,
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
        recovered = _recover_webhook_inflight(state)
        if recovered:
            _write_retry_state(locked_path, state)
            safe_results.extend(recovered)
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
            eligibility, reason, group = (validity_checker or validate_external_weather)(
                record, config, locked_path.parent, int(time.time())
            )
            record["source_checked_at"] = int(time.time())
            record["source_decision"] = eligibility
            record["source_reason"] = reason
            if eligibility != "eligible":
                if eligibility == "cancelled":
                    record.update(completed_at=int(time.time()), terminal_status="cancelled",
                                  terminal_reason=reason, email_pending=False, webhook_pending=[], channels_pending=False)
                safe_results.append({"delivery": delivery_key, "type": "source", "id": "weather",
                                     "status": eligibility, "error": reason})
                _write_retry_state(locked_path, state)
                continue
            if group is not None:
                remaining_email, remaining_hooks = _remaining_external_routes(record, group, config)
                changed = remaining_email != record.get("email_recipients", "") or remaining_hooks != sorted(record.get("webhook_pending") or [])
                record["email_recipients"] = remaining_email
                record["email_pending"] = bool(record.get("email_pending") and remaining_email)
                record["webhook_pending"] = remaining_hooks
                if changed:
                    record["routing_restricted_at"] = int(time.time())
                    safe_results.append({"delivery": delivery_key, "type": "routing", "id": "weather",
                                         "status": "cancelled", "error": "recipient_removed_or_disabled"})
                _write_retry_state(locked_path, state)
            if record.get("channels_pending"):
                context = {'source_validity':record.get('source_validity'), 'event':payload.get('event',''),
                           'severity':payload.get('severity',''), 'deadline_at':min(record['expires_at'],record['created_at']+600),
                           'channels':record['channels']}
                envelope = {'key':delivery_key, 'context':context, 'title':payload.get('subject','Weather alert'), 'body':payload.get('body','')}
                try:
                    process = subprocess.run(['/usr/bin/timeout','40','/usr/bin/php','/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_channels.php'],
                        input=json.dumps(envelope,separators=(',',':')),capture_output=True,text=True,timeout=45,check=False)
                    reply=json.loads(process.stdout) if len(process.stdout)<16384 else {}
                except Exception:
                    reply={'success':False,'status':'deferred','message':'weather_channel_submission_unavailable'}
                status=reply.get('status','deferred')
                if reply.get('success') is True or status in {'failed','cancelled'}:
                    record['channels_pending']=False
                    record['channel_job_id']=reply.get('job_id','')
                receipt={'type':'channels','id':'sms_external_voice','status':status,'error':str(reply.get('message',''))[:400]}
                safe_results.append({'delivery':delivery_key,**receipt});_record_external_receipt(record,receipt)
                _write_retry_state(locked_path,state)
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
                _record_external_receipt(record, {"type":"email", "id":"email", "status":"accepted" if sent else "failed",
                                                  "error":"" if sent else "submission_failed"})
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
                # A slow local email submission can cross a quiet-hour or
                # source-validity boundary. Recheck before starting HTTP.
                eligibility, reason, _group = (validity_checker or validate_external_weather)(
                    record, config, locked_path.parent, int(time.time())
                )
                if eligibility != "eligible":
                    record.update(source_checked_at=int(time.time()), source_decision=eligibility, source_reason=reason)
                    if eligibility == "cancelled":
                        record.update(completed_at=int(time.time()), terminal_status="cancelled",
                                      terminal_reason=reason, webhook_pending=[])
                    safe_results.append({"delivery": delivery_key, "type": "source", "id": "weather",
                                         "status": eligibility, "error": reason})
                    _write_retry_state(locked_path, state)
                    continue
                # Persist intent before entering any transport.  The dispatcher
                # returns receipts for every selected destination, including
                # definite failures for work it did not have time to start.
                record["webhook_inflight"] = sorted(active)
                _write_retry_state(locked_path, state)
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
                receipts = {}
                for result in webhook_results if isinstance(webhook_results, list) else []:
                    if isinstance(result, dict):
                        key = f"{result.get('type')}:{result.get('id')}"
                        if key in active:
                            receipts[key] = result
                uncertain = record.get("webhook_uncertain") or {}
                if not isinstance(uncertain, dict):
                    raise RetryStateError("retry_state_corrupt")
                retryable = set()
                for row in configured_rows:
                    key = f"{row['kind']}:{row['id']}"
                    if key not in active:
                        continue
                    result = receipts.get(key)
                    if not result or result.get("status") not in {"accepted", "failed", "uncertain"}:
                        result = _uncertain_delivery(row, reason="dispatcher_failed")
                    if result.get("status") == "uncertain":
                        uncertain[key] = result
                    elif result.get("status") == "failed":
                        retryable.add(key)
                    safe_results.append({"delivery": delivery_key, **result})
                    _record_external_receipt(record, result)
                record["webhook_pending"] = sorted(retryable)
                record["webhook_uncertain"] = uncertain
                record["webhook_inflight"] = []
                _write_retry_state(locked_path, state)

            if not record.get("email_pending") and not record.get("webhook_pending") and not record.get("channels_pending"):
                record["completed_at"] = int(time.time())
                record["terminal_status"] = ("uncertain" if record.get("webhook_uncertain") else
                                             "partial_cancelled" if record.get("routing_restricted_at") else "complete")
                _write_retry_state(locked_path, state)
        pending = sum(
            1
            for record in state["deliveries"].values()
            if isinstance(record, dict)
            and int(record.get("completed_at", 0) or 0) == 0
            and str((record.get("payload") or {}).get("source") or "").lower() == normalized_source
        )
        uncertain = sum(
            1 for record in state["deliveries"].values()
            if isinstance(record, dict) and record.get("webhook_uncertain")
            and str((record.get("payload") or {}).get("source") or "").lower() == normalized_source
        )
        _write_retry_state(locked_path, state)
    return {"results": safe_results, "pending": pending, "uncertain": uncertain}


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
    config = _config_crypto.read_config(config_path)
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
        try:
            expected_fingerprints = json.loads(os.environ.get("SLS_DESTINATION_FINGERPRINTS_JSON", "{}"))
        except (TypeError, ValueError, json.JSONDecodeError):
            expected_fingerprints = {}
        raw_start = os.environ.get('SLS_DESTINATION_LATEST_START', '')
        if raw_start and not re.fullmatch(r'[1-9][0-9]{0,11}', raw_start):
            print('{"results":[],"error":"invalid_schedule_deadline"}')
            return 1
        try:
            raw_incident = os.environ.get('SLS_DESTINATION_INCIDENT_JSON', '')
            if len(raw_incident) > 1024:
                raise ValueError('oversize incident context')
            incident_context = collaboration_incident_context(json.loads(raw_incident) if raw_incident else None)
        except (ValueError, DestinationError):
            print('{"results":[],"error":"invalid_incident_context"}')
            return 1
        raw_authorization = os.environ.get('SLS_DESTINATION_AUTHORIZATION_JSON', '')
        try:
            if len(raw_authorization) > 16384:
                raise ValueError('oversize authorization context')
            authorization = json.loads(raw_authorization) if raw_authorization else None
            if authorization is not None and (not isinstance(authorization, dict) or
                    set(authorization) - {'api_credential_id', 'automation_context', 'webhook_ids'}):
                raise ValueError('invalid authorization context')
        except ValueError:
            print('{"results":[],"error":"invalid_authorization_context"}')
            return 1
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
            expected_fingerprints=expected_fingerprints,
            latest_start=int(raw_start) if raw_start else None,
            incident_id=os.environ.get("SLS_DESTINATION_INCIDENT_ID", ""),
            incident_context=incident_context,
            authorization_guard=(lambda: _current_authorization(authorization)) if authorization is not None else None,
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
            current_uncertain = (
                external_delivery_uncertain(retry_state_path, source, correlation_key)
                if current_pending is not None else None
            )
        except RetryStateError as exc:
            print(json.dumps({"results": [], "pending": -1, "error": str(exc)}, separators=(",", ":")))
            return 75
        if current_pending is not None:
            outcome["current_pending"] = current_pending
            outcome["current_uncertain"] = current_uncertain
        print(json.dumps(outcome, separators=(",", ":"), ensure_ascii=True))
        return 1 if (
            (current_pending or current_uncertain) if current_pending is not None
            else outcome["pending"] > 0 or outcome["uncertain"] > 0
        ) else 0

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
