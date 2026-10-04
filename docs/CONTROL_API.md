# SLS Mass Notifications Control API

This document describes the `0.1.5-beta` candidate API. Its changes are additive
to the existing routes and authentication. Notification payload schema **1** and
desktop SSE protocol **2** are retained. Features marked **Labs** in the module
need acceptance with the actual devices, providers and desktop client before
operational use.

## Architecture

The Control API is served by the PBX's existing HTTPS web server at
`/api/sls-mass-notify/`. It does not open another listening port. It reads the
protected central configuration, authenticates the credential, checks its scope
and audience, and then calls the same module operations used by the administrator
interface. Status, capability and audience discovery do not bootstrap FreePBX.
Delivery, incident, configuration, fleet and readiness requests use the module
backend. Delivery workers run separately from HTTP requests.

Use the advertised public HTTPS origin. A port forward is supported by configuring
the external Control API port in the setup wizard or **Change advertised address**;
the normal distribution default is port 443. Configure DNS, TLS certificates and
router mappings separately. The PBX does not discover a changed public domain.

The central `.config` uses authenticated AES-256-GCM encryption. Its local keyring
is stored separately in protected, root-owned storage; it is not exposed through
the API. Configuration exports and FreePBX backup archives contain sensitive
material and must be stored privately. Operational delivery journals, receipts
and incident history are separate from configuration.

The APIs have separate authentication boundaries:

| Interface | Path | Authentication and purpose |
| --- | --- | --- |
| Control API | `/api/sls-mass-notify/` | Named API credential or existing legacy key; administration, announcements and incident workflows |
| Desktop API | `/api/sipnotify/desktop` | Saved desktop username/password using HTTPS Basic authentication; only that desktop's routed events |
| Signed trigger | `/api/sls-mass-notify/trigger.php` | Rule-specific HMAC signature; saved sensor/panic activation |
| SMS callback | `/api/sls-mass-notify/sms-callback.php` | Supported provider signature; delivery receipts and authenticated inbound opt-outs |
| Operator portal | `/mass-notify/` | Separate operator login, password and TOTP; administrator-configured permissions |

An API credential is not an operator login or desktop password. Provider callbacks
do not accept Control API credentials. No API request can choose an executable,
an arbitrary SMS number, a mail sender identity or a new outbound route.

## Enable access and create a credential

1. Complete the setup wizard and apply the saved configuration.
2. Enable the Control API under **General Settings**. Review its IP allowlist and
   requests-per-minute limit. Keep internet exposure limited to the intended route.
3. Create a named credential in the administrator credential manager. Select the
   required scopes and permitted recipients or saved groups. Copy its secret once
   and store it in the integrating service's secret store.
4. Use `resource=capabilities` and `resource=audiences` to inspect the operations
   and saved identifiers available to that credential.

Named credentials have random 256-bit secrets. Only their SHA-256 digests and
permission metadata are stored. The administrator can revoke them immediately;
workers recheck current permissions before delayed delivery. The existing legacy
key remains compatible but has unrestricted authority and cannot perform incident
mutations. Prefer a separate named credential for each integration.

| Scope | Authority |
| --- | --- |
| `read` | Status, authorized discovery and delivery evidence; unrestricted credentials can also read event history, fleet, readiness and incident reports |
| `send` | Announcements, announcement previews, eligible explicit retries; named unrestricted credentials can start/update incidents |
| `test` | Weather tests for permitted saved zones |
| `config` | Redacted configuration reads and validated configuration changes; requires an unrestricted audience |

GET configuration reads require both `read` and `config`. The POST `get_config`
action requires `config`. Configuration and incident operations require an
unrestricted audience. Restricted announcement senders can address only their
explicit extensions, desktop IDs, saved group membership, external voice IDs,
saved mail/SMS IDs and webhook IDs. Restricted delivery reads/retries are limited
to that credential's own jobs.

Send the secret in either header:

```http
Authorization: Bearer sls_<credential-secret>
```

```http
X-API-Key: sls_<credential-secret>
```

Do not put secrets in URLs, source control or logs. Query-string authentication,
browser cookies and untrusted forwarded addresses do not authorize these routes.
Use verified HTTPS. A direct loopback HTTP exemption exists for local maintenance;
proxied requests never receive that exemption.

For an administered reverse proxy, configure its exact address/CIDR in the
administrator UI and make the proxy overwrite `X-Forwarded-For` and
`X-Forwarded-Proto`. Unlisted peers cannot supply a trusted client address.
Malformed trusted-proxy headers fail closed. Forwarded hostnames are not trusted.

## Request and response rules

GET selects a `resource`; omission means `status`. POST uses
`Content-Type: application/json` and an object containing an `action` string.
String, integer, list and boolean fields must use their actual JSON types.
`"false"` and `0` are not interchangeable with `false`.

Ordinary actions accept at most **64 KiB**. `update_config` accepts at most
**2 MiB**. JSON nesting is bounded. Unknown resources and malformed query values
return 400. There is no generic arbitrary-method RPC.

Responses are JSON with an explicit `ok` boolean, and module operations generally
also return `success` and a readable `message`. Inspect both HTTP status and body.
Successful HTTP acceptance does not establish delivery to a handset or inbox.
Responses are uncached. These examples use placeholder IDs; retrieve and replace
them with saved IDs from your own PBX.

### GET resources

| Resource | Query fields | Requirements and result |
| --- | --- | --- |
| `status` | None | `read`; restricted credentials receive only whether Control API access is enabled |
| `capabilities` | None | `read`; available actions/resources, scopes, schema/protocol versions, body/page limits and endpoint paths |
| `audiences` | None | `read`; permitted saved IDs/names, desktop usernames and configured phone targets; no external numbers, mail addresses, webhook URLs or credentials |
| `delivery` | `job_id` | `read`; announcement state and independent channel receipts; restricted credentials see only their own jobs |
| `events` | Optional `limit`, `cursor` | Unrestricted `read`; bounded newest-first operational history |
| `readiness` | None | Unrestricted `read`; deployment readiness checks; no alert is sent |
| `desktop_fleet` | None | Unrestricted `read`; saved fleet, recent authenticated activity and client-reported versions/protocols |
| `config` | None | Unrestricted `read` and `config`; secret-redacted active configuration and whether staged changes exist |
| `incidents` | Optional `limit`, `cursor`, `archived=0` or `1` | Unrestricted `read`; incident list, with separate archived view |
| `incident` | `incident_id` | Unrestricted `read`; incident record, timeline and response state |
| `incident_report` | `incident_id`, optional `job_offset`, `revision` | Unrestricted `read`; paginated report with live job evidence |
| `enterprise_capabilities` | None | Named `read`; enabled Labs capability booleans and supported contracts; no credentials or integration configuration |
| `incident_templates` | None | Named `read`; templates whose primary, shifted and escalation audiences all fit this credential |

Announcement IDs have the form `job_` plus 32 lowercase hexadecimal digits.
Incident IDs use `inc_` plus 32 lowercase hexadecimal digits. Treat them as opaque
identifiers. A job ID and a published desktop event ID are different identifiers.
The discovery `phone_targets` list contains targets already present in saved SLS
routing; it is not a complete PJSIP registration inventory or a delivery promise.

### POST actions

| Action | Scope | Purpose |
| --- | --- | --- |
| `send_announcement` | `send` | Submit one immutable announcement using saved/authorized recipients |
| `preview_announcement` | `send` | Resolve and validate recipients/options; return a preview without creating a delivery job or submitting any channel |
| `retry_announcement` | `send` | Deliberately retry eligible confirmed failures for an existing `job_id` |
| `test_nws`, `trigger_nws_test` | `test` | Start a marked Weather test using current permitted zone routing |
| `get_config` | `config` | Return the same redacted active configuration as the GET resource |
| `update_config` | `config` | Validate/stage a supported settings patch, or apply with explicit `apply: true` |
| `start_incident` | Named unrestricted `send` | Start an incident or planned drill from a saved template |
| `update_incident` | Named unrestricted `send` | Send a separately logged update or all-clear; previously sent announcements remain immutable |
| `incident_roll_call` | Named unrestricted `config` | Record a human response for a saved roster identity |
| `incident_checklist` | Named unrestricted `config` | Record checklist completion for the incident |
| `enterprise_template_start` | Named `send` | Launch an enabled coordination template permitted by the current credential; immutable `request_id` |

## Announcement contract

The required message is a nonempty string of at most **500 characters**. Use
`message`; existing `body` and `text` aliases remain compatible. Select at least
one destination. A preview checks the same authorized resolution path and does
not prove audio synthesis, provider availability or physical delivery.

| Field | Type and behavior |
| --- | --- |
| `targets` | List of internal extension strings; `extensions` is an existing alias |
| `groups` | Saved announcement group IDs or names; `announcement_groups` is an alias |
| `desktop_clients` | List of saved desktop usernames or client IDs; `desktop_targets` is an alias |
| `desktop_all` | Boolean; request all enabled desktops, subject to audience authorization; `all_desktops` is an alias |
| `phones_all` | Boolean; request all available internal phone targets, subject to authorization; `all_phones` is an alias |
| `desktop` | Existing boolean compatibility flag; explicit desktop selectors determine the routed audience |
| `voice_recipient_ids` | List of enabled saved external voice IDs; requires an audio mode other than `none` |
| `email_recipient_ids` | Distinct enabled saved mail IDs, at most 50; raw mail addresses are not accepted |
| `sms_recipient_ids` | Distinct enabled saved SMS IDs with recorded consent, at most 50; raw numbers are not accepted |
| `webhook_ids` | At most ten enabled saved announcement webhook IDs |
| `title` | String; announcement heading, limited to 80 characters by the delivery resolver |
| `style` | `standard` or `colored`; existing `image` and `nws` style aliases select the colored image |
| `image` | Boolean; request generated colored-announcement rendering |
| `background_color` | Six-digit CSS hex color, for example `#19334a` |
| `priority` | `normal` or `urgent`; urgent prepared audio takes precedence over routine audio waiting for overlapping recipients, without interrupting active audio or bypassing cooldown |
| `audio_mode` | `none`, `tones`, `tts` or `tones_tts` |
| `tts` | Existing boolean compatibility flag; use explicit `audio_mode` for new integrations |
| `opening_tone`, `closing_tone` | Saved tone identifiers; omit for configured defaults, or use an empty string for no tone |
| `piper_voice` | Optional installed voice path from saved configuration; arbitrary voice/model downloads are not supported |
| `tts_volume` | Optional integer 1–200; 100 represents the configured nominal amplitude |
| `is_test` | Explicit boolean; marks the announcement as a test without inferring intent from its wording |
| `preview` | Optional boolean equivalent to previewing this request; `preview_announcement` always forces preview |

The `options` object accepts `style`, `image`, `title`, `background_color`,
`audio_mode`, `opening_tone`, `closing_tone`, `priority`, `desktop_all`,
`phones_all`, `desktop_clients`, `voice_recipient_ids`, `email_recipient_ids`,
`sms_recipient_ids`, `webhook_ids`, `piper_voice`, `tts_volume`, `preview` and
`is_test`. Keep `message`, phone/group selectors and compatibility aliases at the
top level. Top-level fields take precedence when supplied. Internal option names
such as `_test_channels`, `_is_test` and `incident_context` are rejected. The
server determines the authenticated sender; callers cannot claim another sender
or manufacture incident lifecycle metadata.

Saved group recipients expand when admitted, then the worker retains only
originally selected identities still authorized at dispatch. New group members
do not receive a queued old announcement. Audio beyond the configured speech
duration limit is rejected with a clear error rather than silently cut short.
External voice calls play their tone/speech only after their own call is answered.

### Discover and preview using curl

Set these variables from your service's protected configuration. Avoid putting
the literal credential into copied examples or persistent shell history.

```bash
SLS_ORIGIN='https://pbx.example.com'
SLS_KEY='<named-credential-secret>'
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $SLS_KEY" \
  "$SLS_ORIGIN/api/sls-mass-notify/?resource=capabilities"
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $SLS_KEY" \
  "$SLS_ORIGIN/api/sls-mass-notify/?resource=audiences"
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $SLS_KEY" -H 'Content-Type: application/json' \
  --data '{"action":"preview_announcement","message":"Reviewed test message","targets":["1000"],"audio_mode":"tones_tts","is_test":true}' \
  "$SLS_ORIGIN/api/sls-mass-notify/"
```

### Submit and inspect delivery

Submitting this example sends to the selected saved recipients. Run it only
against an authorized test audience after replacing its placeholder IDs.

```json
{
  "action": "send_announcement",
  "message": "This is an authorized notification system test.",
  "title": "System test",
  "targets": ["1000"],
  "desktop_clients": ["test.desktop"],
  "audio_mode": "tones_tts",
  "style": "colored",
  "background_color": "#19334a",
  "priority": "normal",
  "is_test": true
}
```

An accepted HTTP request ordinarily returns:

```json
{
  "ok": true,
  "success": true,
  "action": "send_announcement",
  "queued": true,
  "state": "worker_starting",
  "job_id": "job_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "message": "Announcement queued. Waiting for delivery results."
}
```

Read `resource=delivery&job_id=<returned-id>` while the job runs. Receipts identify
each channel and target independently. Preserve the original response and job ID.
An HTTP timeout after submission does **not** authorize a new send. Ordinary
announcements have no caller idempotency key; check existing delivery evidence
before deciding whether a deliberate new announcement is necessary.

| Evidence | Meaning |
| --- | --- |
| Desktop `published` | The event was published to the live feed; application receipt remains unconfirmed |
| Desktop `received` | **Received by desktop app** for this exact event and desktop identity; no person is asserted to have read it |
| SIP NOTIFY `submitted` | Asterisk accepted the request; physical handset display is not proved |
| Audio `queued` | Asterisk queue admission; later correlated answer/playback events add evidence, without proving a person heard it |
| External voice answer/playback | Evidence for that individual call, not an inference from another answered handset |
| Mail accepted | **Accepted by PBX mail service**; inbox delivery and human reading are not proved |
| SMS provider `delivered` | The provider reports delivery; human reading is not proved |
| Webhook accepted | The destination accepted its HTTP request; downstream action/display still requires its own confirmation |

Retries accept only `action` and `job_id`. Already accepted or uncertain channels
are not repeated. SMS is never automatically retried and its receipt is not
eligible for a generic resend. Permission revocation, original expiry and changed
recipient identity are rechecked. Ordinary announcements cannot be edited or
cancelled after sending.

## Event history and incident reports

Control API event history is an audit view, not a desktop replay queue. Request
`resource=events&limit=25`, then follow the opaque `next_cursor` while `has_more`
is true. Each page returns at most 100 records and scans at most 512 KiB.
`scan_limited: true` means the scan budget was reached; a short page alone does
not establish the end of history. Cursors freeze one file prefix while events
append. A rotated/compacted log returns 409 `event_log_changed`: restart without
the old cursor. Invalid cursors return 400; busy or unavailable storage returns
503 and `Retry-After`.

Incident mutations require a client-generated **32-character lowercase hex**
`request_id`. Reuse that same ID for a retry of the same operation. Conflicting
reuse is rejected. Never invent a new request ID merely because the response
timed out. Inspect the incident and linked job first. Interrupted/uncertain
submission is preserved for review and is not automatically replayed.

```json
{
  "action": "start_incident",
  "template_id": "tpl_aaaaaaaaaaaaaaaaaaaaaaaa",
  "request_id": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "fields": {"location": "North entrance"},
  "is_test": true
}
```

Replace the template ID and `fields` with a saved, reviewed template. A planned
drill additionally supplies a timezone-aware `planned_at`; a saved reviewed
translation can be selected with `language_variant`. An update/all-clear uses
`action: "update_incident"`, `incident_id`, a new operation `request_id`,
`kind: "update"` or `"all_clear"`, and reviewed `message` wording. It creates a
new immutable announcement; it does not remove an earlier notification.

Roll call requires `person_id` and `response` (`safe`, `received`,
`needs_assistance`, `no_response` or `missing`), with an optional `note`.
Checklist updates require integer `item` 0–24, boolean `complete`, and an optional
`note`. Both require `incident_id` and their operation `request_id`.

Incident lists support a 1–200 record `limit` and opaque `cursor`. For reports,
follow `next_job_offset` until `complete: true`, passing the first page's
`revision` on subsequent requests. Restart if the incident revision changes.
Job receipts are live evidence and can change independently during the export.
The complete frozen audience, language, escalation, archival and desktop human
response contracts are documented in [INCIDENT_API.md](INCIDENT_API.md).

## Configuration administration

Use a narrowly assigned unrestricted `config` credential. `get_config` returns
active configuration with secrets replaced by `[redacted]` and a `pending`
indicator. It is not a recoverable configuration backup. Supplying a redacted
placeholder in a patch preserves the existing secret where supported.

`update_config` accepts `settings` (`config` is an existing alias) and optional
boolean `apply`. The default stages the patch; `apply: true` validates and applies
the resulting configuration. Staged patches merge with already pending settings,
so coordinate writers rather than sending stale complete copies.

```json
{
  "action": "update_config",
  "settings": {
    "quiet_hours_enabled": true,
    "quiet_hours_start": "22:00",
    "quiet_hours_end": "07:00"
  },
  "apply": false
}
```

Supported settings include alert enablement and recipients; quiet hours;
Weather zones; Lightning groups/provider settings; saved announcement audiences;
speech voices, tone selections, volume and duration limits; display timeout;
phone/desktop capacities; external voice, mail and SMS settings; supported webhook
destinations; retention/cache limits; update policy and saved test profiles.
Unknown settings, invalid types and unsupported nested fields are rejected with
validation details. The capacity/resource gate applies to API changes as well as
the UI. The old global `mail_to` alert-recipient field is not writable; configure
the relevant Weather/Lightning destinations or `system_notification_emails`.

API credentials, trusted proxy configuration, operator logins/TOTP, approved
scripts, enrollment secrets, incident templates and schedules remain
administrator UI operations. A named config credential cannot change the legacy
API key.
The API does not offer root commands, FreePBX module installation, arbitrary
configuration-file paths, unrestricted file downloads or a direct PBX reboot.

## Weather tests, signed triggers and SMS callbacks

A Weather test uses `action: "test_nws"`, `mode`, optional `trigger_name`,
`zone_scope: "all"` or `"selected"`, and `zone_ids` for the selected scope.
Restricted test credentials must select at least one explicitly permitted saved
zone. The test uses that zone's current routing; it is not a no-delivery probe.
For example, only after reviewing the saved test zone's recipients:

```json
{
  "action": "test_nws",
  "mode": "tts",
  "zone_scope": "selected",
  "zone_ids": ["test_zone"],
  "trigger_name": "Authorized integration acceptance"
}
```

Saved sensor and panic rules use a separate signed route. For
`POST /api/sls-mass-notify/trigger.php?rule_id=trg_<24 hex>`, sign these exact bytes
with HMAC-SHA256 using the saved rule enrollment secret:

```text
rule_id + "." + timestamp + "." + exact_raw_JSON_body
```

Send the current ten-digit Unix timestamp in `X-SLS-Timestamp` and lowercase hex
signature in `X-SLS-Signature`. Requests require verified HTTPS and are limited
to 16 KiB. Sensor activation includes an event name and stable request ID; panic
activation first obtains a challenge and then supplies its confirmation. The rule
determines recipients and approved actions; the caller cannot change them.
See [TRIGGERS.md](TRIGGERS.md) for exact bodies, freshness, cooldown and approved
script constraints.

Twilio and Telnyx callbacks authenticate the original provider body/signature
against the configured advertised HTTPS callback URL. Delivery callbacks must
also match the exact local delivery, provider identity and recipient route.
Authenticated inbound STOP messages persist suppression, even when new SMS
sending is disabled. Replayed STOP identities do not renew suppression after
new consent. Callback failure to persist evidence returns retryable 503.
BulkVS submission and read-only sender diagnostics are supported; this adapter
does not accept an unauthenticated BulkVS webhook or claim verified inbound STOP
handling. Review [SMS.md](SMS.md) for setup, consent, budgets, MMS and limitations.

## Desktop compatibility

The desktop API retains existing HTTPS Basic authentication, paths, payload schema
1 and SSE protocol 2. SSE `notification` IDs match the published event's ID.
SSE heartbeat activity is sent approximately every 15 seconds, and disconnected
stream slots are released. Fleet capacity can exceed the bounded simultaneous
SSE worker allocation; a capacity response supplies five-second JSON polling
fallback information and `Retry-After`.

Resumable polling uses `last_event_id`, `has_more` and the returned cursor. Drain
full pages with the returned cursor so bursts are not skipped. A fresh empty
cursor establishes a live baseline. The desktop client intentionally skips
announcements missed while disconnected or asleep and enforces a maximum
ten-minute announcement lifetime, including server display timeout zero.
Published IDs, timezone-aware creation times and absolute expiry remain stable.
Audit delivery records are retained separately from the display window.

`POST /api/sipnotify/desktop/ack` with `{"event_id":"<published-event-id>"}`
records application receipt only for an event routed to the authenticated desktop.
Duplicates are safe. A successful response contains `ok: true`, the same
`event_id` and `receipt_type: "client_acknowledgement"`; storage failure returns
503 with `retryable: true`. Human incident responses use the separate incident
contract, not this software acknowledgement.

Clients can report `X-SLS-Client-Version`, `X-SLS-Payload-Schema` and
`X-SLS-SSE-Protocol` on authenticated requests. The fleet stores bounded reported
metadata as advisory inventory. Generated colored-announcement images use an
unguessable/versioned HTTPS URL on the advertised PBX origin and remain below
the desktop's 5 MiB limit. The existing desktop image request sends no Basic
authentication or browser cookies; changing that requires coordinated client work.

## Errors, throttling and safe retries

| HTTP status | Typical cause and handling |
| --- | --- |
| 400 | Invalid resource, query, JSON shape, unsupported action or rejected settings; correct the request |
| 401 | Missing, incorrect or revoked credential |
| 403 | Disabled API, IP restriction, missing scope, unauthorized audience or unnamed incident mutation |
| 405 | Method not allowed; Control API supports GET and POST |
| 409 | Event history cursor no longer describes the retained file prefix |
| 413 | Request exceeds its explicit body limit |
| 415 | POST media type is not `application/json` |
| 422 | A signed trigger's JSON is invalid or its saved rule rejects the requested activation |
| 426 | HTTPS required |
| 429 | Configured rate limit/cooldown/stream capacity; honor `Retry-After` |
| 500 | Unexpected backend error; inspect the PBX logs and existing job/incident evidence before a mutation retry |
| 503 | Protected configuration, rate/audit/receipt storage or runtime unavailable; read failures can be retried, uncertain mutations require evidence review |

Error JSON uses explicit codes such as `unauthorized`, `permission_denied`,
`audience_not_permitted`, `invalid_announcement_fields`, `rate_limited`,
`config_unavailable` and `status_unavailable`. Module validation results can also
contain `errors`, `error_code`, `delivery_started`, `retryable`,
`submission_uncertain` or `settings_replaced`. Do not treat HTTP status alone as
proof that a failed mutation had no effect.

The Control API has a configurable per-IP request limit, 1–600 per minute when
enabled. Multiple integrations behind the same public IP share that budget.
Desktop authenticated traffic has a separate per-client budget from failed-login
protection, so a shared NAT does not combine legitimate desktop polling into one
small Control API allowance. Never disable protection to hide storage failures.

Control actions are audited without secrets or message bodies. If audit evidence
cannot be confirmed, the response carries `X-SLS-Audit-Status: unavailable` and
`X-SLS-Audit-Error`. The action response remains unchanged because submission may
already have occurred. Preserve the result, repair audit storage/forwarding and
review diagnostics; do not resend an accepted action to recreate its audit entry.

**Help > Recent Control API Use** shows requests that supply an API key, including
local health probes. Requests without a key remain in the protected API journal.
Operator sign-ins, password recovery and administrator security events use a
separate journal displayed under **Operator Access**.

## Integration pattern

Use a dedicated credential, discover authorized IDs, validate with a preview,
submit once, preserve the job ID and poll its receipts. Use bounded exponential
backoff for read failures and honor `Retry-After`. Avoid frequent readiness or
full fleet/configuration requests on every desktop poll; cache discovery within
the integrating service and refresh it when configuration changes.

Keep application receipt, provider acceptance, physical playback and human
response as different evidence types in your integration. Validate on the actual
production HTTPS route and with an explicitly authorized test audience before
using the workflow for operational alerts.

## Enterprise Labs contracts

These additions preserve notification payload schema 1, SSE protocol 2 and existing endpoint paths. Every new feature starts disabled. Only the FreePBX administrator panel can activate Labs namespaces or acknowledge dangerous features; `update_config` cannot do so.

Discover `?resource=enterprise_capabilities` and `?resource=incident_templates` using a named `read` credential. The template list filters every primary, active-shift and escalation audience. Launch requires the same current named credential with `send`, enabled incident coordination and a fresh immutable request identifier:

```json
{
  "action": "enterprise_template_start",
  "template_id": "tpl_0123456789abcdef01234567",
  "request_id": "0123456789abcdef0123456789abcdef",
  "fields": {"location": "North lobby"},
  "is_test": true
}
```

Supply only fields declared by the saved template. Repeated identical request IDs return their existing incident; changed contents are rejected. The response is a bounded incident summary with operation states/IDs. A state of `awaiting_approval` means no delivery has started. API keys cannot supply human approval evidence. `queued` means admission, not verified playback, desktop receipt or human response.

Sensor heartbeat messages use the existing signed `/trigger.php?rule_id=...` endpoint with `operation: "heartbeat"`. HTTPS, the enrolled sensor rule, HMAC timestamp/signature and bounded request expiry are required before heartbeat handling. Activation examples retain the existing `operation: "activate"` facade. See [integration examples](ENTERPRISE_INTEGRATIONS.md).

Peer traffic is a separate disabled interface requiring explicitly provisioned mutual TLS, pinned certificate identity, HMAC, epoch/timestamp and permanent replay protection. It does not accept a Control API bearer credential. Subscriber, operator human responses and provider callbacks have their own verification contracts; they are not interchangeable with automatic desktop delivery acknowledgments.

## Runtime admission and one-way delivery

An administrator can pause new SLS notification admission with `sudo slsconsole stop`. An announcement submitted while paused returns `success: false`, `error_code: runtime_stopped` and `delivery_started: false`; resume with `sudo slsconsole start`. Receipt ingestion, status and administrative recovery remain available. Pausing never extends a queued event's original expiry.

Webhook receipts retain `http_status` and a safe `failure_code` when available. `accepted` means the webhook service accepted the HTTP request, not that a person read it. `uncertain` with `needs_attention: false` means response confirmation is unavailable for this one-way submission. It must not be replayed automatically. A definite transmission failure or HTTP rejection remains a failure; uncertainty in other channels retains its existing meaning. Historical receipts are preserved, with display-only projections clearing obsolete webhook dashboard faults.
