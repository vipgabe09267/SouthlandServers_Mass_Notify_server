# Desktop readiness reporting

The existing Basic authentication, endpoint paths, payload schema 1 and SSE
protocol 2 remain unchanged. Compatible apps can attach these optional headers
to polling, streaming and acknowledgement requests:

```http
X-SLS-Client-Version: 1.2.3
X-SLS-Payload-Schema: 1
X-SLS-SSE-Protocol: 2
```

These values describe the installed app version and protocols the connection
uses. They are authenticated self-reports, not attestation. Do not send the
computer name, operating-system fingerprint or user identity in these fields.
The version is limited to 64 characters and protocol numbers to three digits.
Invalid metadata is ignored without blocking notification delivery.

Help & Diagnostics displays the latest reported version and readiness. General
Settings defines an advisory minimum app version, stored as
`desktop_minimum_version` in the central `.config`. An empty value uses the default `1.0.13-beta`; explicit policies accept
`major.minor.patch` or an alpha/beta/rc prerelease such as `1.2.3-beta.1`.
This policy flags upgrades; it does not revoke or block a client. Revocation
continues to use the client's Enabled control and existing authentication.

Metadata is bound to the configured client ID. Reusing a username for a different
client cannot inherit its version or advisory receipt state. Requests without
reporting headers preserve the same client's previous report, including its
original report time. Reports older than 24 hours are marked stale. Legacy apps
that have never reported remain unknown; the server does not infer a version
from successful authentication or receipt.

An unrestricted Control API credential with the `read` scope can fetch
`GET /api/sls-mass-notify/?resource=desktop_fleet`. The response adds `schema: 1`,
`generated_at`, `minimum_version` and a bounded `clients` list. Each row contains
configured identity, enabled state, authenticated last-seen information, advisory
receipt fields, `client_version`, `client_reported_at`, `payload_schema`,
`sse_protocol`, `connection` and `compatibility`.

Connection values are `disabled`, `never`, `clock_error`, `streaming`, `recent`
or `inactive`. Recent means authenticated activity within 90 seconds, which can
include diagnostics. Compatibility values are `not_reported`, `stale_report`,
`protocol_not_reported`, `unsupported_protocol`, `upgrade_required`,
`meets_minimum` or `no_minimum`.
Neither connection nor compatibility confirms receipt or human reading; the
existing exact-event acknowledgement ledger remains authoritative for app receipt.

The current desktop app must add these headers to populate version reporting.
Fresh installations default to a minimum version of `1.0.13-beta`. Upgrades
preserve an existing explicit policy. This is an advisory upgrade check;
existing authenticated clients continue working.

PHP-FPM uses the existing authenticated JSON polling path instead of holding a
shared worker for each desktop stream. Stream requests return the existing
`stream_capacity_reached` response with `reason: php_fpm_worker_pool`, polling
instructions and `Retry-After`. Apache module PHP continues to support SSE.

## Transport negotiation

Start with `GET /api/sipnotify/desktop?last_event_id=` without `If-None-Match`
to establish a live-only cursor and read the transport preference. A normal
poll response has `transport: "json_poll"`, `cursor_parameter: "last_event_id"`
and `poll_interval_seconds: 5`, plus this additive object on PHP-FPM:

```json
{
  "transport_capabilities": {
    "preferred": "json_poll",
    "sse_available": false,
    "reason_code": "shared_php_fpm_pool",
    "sse_protocol": 2,
    "payload_schema": 1
  }
}
```

On Apache module PHP, the polling response still has `transport: "json_poll"`,
but advertises SSE for the next connection:

```json
{
  "transport_capabilities": {
    "preferred": "sse",
    "sse_available": true,
    "reason_code": "bounded_stream_slots",
    "sse_protocol": 2,
    "payload_schema": 1
  }
}
```

SSE availability describes the runtime; it does not reserve a stream slot.
Follow the polling preference without repeatedly attempting an FPM stream.
An FPM stream request returns HTTP 429, `Retry-After: 300`, the error
`stream_capacity_reached`, `reason: "php_fpm_worker_pool"` and a `fallback`
object with the polling transport, path, cursor parameter and five-second
interval. Slot exhaustion can omit `reason` and use `Retry-After: 15`.
A successful SSE handshake advertises `transport: "live_sse"`,
`protocol_version: 2` and `heartbeat_seconds: 15`.

The authenticated ETag fast path returns HTTP 200 with `unchanged: true` and
an empty event list. It can omit `transport_capabilities`, `source` and `client`.
Keep previously negotiated capabilities for that exact origin, account and
enrollment; absence does not mean SSE became available. Keep existing popups
and pending receipts. Process every new event and drain `has_more` pages using
the returned opaque `last_event_id`. A healthy polling connection is connected.

The additive `upgrade_required` boolean flags a missing, stale, invalid or below-minimum app version. It remains advisory. Protocol compatibility is reported separately, and version reporting never substitutes for authentication or a delivery receipt.
