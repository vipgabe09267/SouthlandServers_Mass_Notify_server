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
Settings optionally defines a minimum app version, stored as
`desktop_minimum_version` in the central `.config`. Accepted policies are empty,
`major.minor.patch`, or an alpha/beta/rc prerelease such as `1.2.3-beta.1`.
This policy flags upgrades; it does not revoke or block a client. Revocation
continues to use the client's Enabled control and existing authentication.

Metadata is bound to the configured client ID. Reusing a username for a different
client cannot inherit its version or advisory receipt state. Requests without
reporting headers preserve the same client's previous report, including its
original report time. Reports older than 24 hours are marked stale. Legacy apps
that have never reported remain unknown; the server does not infer a version
from successful authentication or receipt.

An unrestricted Control API credential with the `read` scope can fetch
`GET /api/sls-mass-notify?resource=desktop_fleet`. The response adds `schema: 1`,
`generated_at`, `minimum_version` and a bounded `clients` list. Each row contains
configured identity, enabled state, authenticated last-seen information, advisory
receipt fields, `client_version`, `client_reported_at`, `payload_schema`,
`sse_protocol`, `connection` and `compatibility`.

Connection values are `disabled`, `never`, `clock_error`, `streaming`, `recent`
or `inactive`. Recent means authenticated activity within 90 seconds, which can
include diagnostics. Compatibility values are `not_reported`, `stale_report`,
`unsupported_protocol`, `upgrade_required`, `meets_minimum` or `no_minimum`.
Neither connection nor compatibility confirms receipt or human reading; the
existing exact-event acknowledgement ledger remains authoritative for app receipt.

The current desktop app must add these headers to populate version reporting.
No minimum version is imposed by an upgrade and existing clients continue working.
