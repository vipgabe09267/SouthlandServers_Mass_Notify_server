# Incident workflows — candidate contract 1

These additions are included in `0.1.5-beta`. Notification
payload schema 1, SSE protocol 2, existing authentication and existing endpoint
paths remain compatible. The desktop app needs a coordinated UI update to offer
human responses; its existing delivery acknowledgments retain their meaning.

## Administrator workflow

**Incidents and Drills** manages templates with a title, message, required operator
fields, severity, destinations, audio/tones, roster and observer checklist.
Templates are saved in `incident_workflows` inside the protected central `.config`.
Save and Apply Config before starting the new template version. Operational
history is stored separately under the module data directory's `incidents/`.

Starting an incident freezes its template and resolved audience. Later group
additions do not join the incident. Desktop identity, external number/routing, saved email address/sender identity and
webhook destination changes reject a subsequent submission. An update or all-clear
creates another immutable announcement with its own job and event ID. It does not
edit, cancel, dismiss or extend an earlier notification. General Settings controls
the existing display timeout; the desktop app's ten-minute cap still applies.

Drills have explicit `is_test: true` and can be scheduled for an absolute,
timezone-aware timestamp. The minute worker skips drills more than fifteen minutes
late. An optional escalation ladder sends up to five configured follow-ups for
outstanding human responses. Every step has its own frozen audience and durable
attempt; it does not infer human safety from a software delivery receipt.

## Escalation ladder

Templates accept `escalation: {enabled, steps}`. Each of at most five steps has a
`name` (1–80 characters), increasing integer `after_seconds` (60–86400), and the
same `delivery` selectors/options as the initial announcement. An enabled policy
requires a person roster and an explicit audience for every step. Existing
`{enabled, after_seconds, delivery}` policies retain their one follow-up. Normalized
settings expose the first step through those legacy fields; conflicting legacy
and new fields are rejected, so an audience cannot hide from permission checks.

Example template fragment, with separately saved recipients:

```json
{
  "escalation": {
    "enabled": true,
    "steps": [
      {"name": "Site supervisor", "after_seconds": 300,
       "delivery": {"extensions": ["1000"], "audio_mode": "tts"}},
      {"name": "Operations desk", "after_seconds": 600,
       "delivery": {"desktop_clients": ["operations.desktop"]}}
    ]
  }
}
```

The minute worker sends at most one next step per incident in a pass. Delays start
when the initial job is durably linked. When the worker is delayed, later steps
also retain their configured interval from the preceding attempt; overdue levels
are never fired together to catch up. Human responses `safe` or `received`
satisfy a participant; `no_response`, `missing` or `needs_assistance` keep the
ladder eligible. All-clear stops future steps. Every dispatch rechecks current
recipient identity, consent, routing and originating credential permissions.
Operator launch/update permissions cover all enabled steps, not just the first.

An uncertain or interrupted submission pauses automatic progression; inspect its
job and record before taking a deliberate new action. SLS never replays that
attempt. A definite pre-send rejection is recorded as `not_submitted`; a separately
configured later audience may still receive its own step at its due time.
Operations add `escalation_step` and `escalation_name` for report/display purposes.
Existing incident IDs, first-step request IDs, notification payload schema 1,
SSE protocol 2 and receipt semantics remain unchanged. Sent alerts cannot be
edited or cancelled. The administrator editor retains disabled drafts and provides
Add, Remove and ordering controls. Template changes need Save and Apply Config;
existing incidents retain their original ladder.

## Reviewed resources and language variants

Templates can contain up to ten `resources`: each has `kind` (`map`,
`instructions` or `reference`), a `label` of at most 80 characters, a complete
`https://` URL of at most 2,048 characters, a `revision` or review note of at most
100 characters, and explicit boolean `reviewed: true`. Embedded credentials,
control characters, duplicate URLs and other URL schemes are rejected.

Use versioned links to approved floor plans, evacuation instructions and responder
references. Confirm access from responders' accounts. The incident page opens
links in a separate tab without passing its opener or referrer. SLS does not fetch,
embed or host these documents, and does not append links to outgoing announcements.
A remote document's contents can change independently; its owner must retain the
reviewed revision. The exact saved links/notes are frozen in the incident and its
export even if a template changes later. These are operator resources; desktop
resource presentation would require coordinated client support.

Templates can also hold six `language_variants`. Each contains `locale` (a short
language tag such as `es-MX`, normalized to lowercase), `label` (80 characters),
`title` (80), `message` (500), `review_note` (100; identify reviewer/revision), and
boolean `reviewed: true`. Every variant must use the same declared `{{fields}}` as
the base wording. Editing a resource or translation clears its review checkbox.
No automatic translation, language detection or audience inference takes place.

At launch, the operator previews one saved variant for the complete audience.
`start_incident.language_variant` selects its saved locale; omission or `""` uses
the default wording. Unknown or invalid choices fail before creating a job.
Incident speech supports English, Spanish, French, German and Portuguese. A
reviewed language variant selects a matching installed model; regional variants
use the catalog's available accent (Spanish from Spain, Portuguese from Brazil).
Initial and supervisor deliveries freeze their internal and external voice paths.
Later updates, all-clear, planned drills and escalations retain those selections.
Each submission checks both model/config checksums before submitting any channel.
Missing models and unsupported spoken languages fail before the initial send;
unsupported text variants can still use no speech or tones only for every step.
Ordinary incident wording preserves the administrator's internal/external voice
choices, including the separate external quality setting. Spoken announcement
prefixes and supervisor status counts use the model/variant language. Required
drill/test markers remain explicit. SLS never translates the supplied message or
verifies the accuracy of a reviewer-approved translation.

The selected title/message and locale are frozen for immediate and planned-drill
jobs. Changing templates cannot rewrite them. Changing a language while reusing
an already submitted request ID is rejected. Drill/test markers remain explicit;
updates and all-clear require their own reviewed wording. Notification payload
schema 1 and SSE protocol 2 are unchanged. Resource/language metadata stays in the
operator incident record/report rather than changing the desktop payload contract.

## Email destinations

General announcement email is included in `0.1.5-beta`.
Validation uses isolated sender and browser fixtures; no real external mail or
inbox receipt has been verified. Enable
**General Settings > Announcement Email**, save named recipients, configure the
existing sender identity and Apply Config. The channel is disabled by default and
supports at most 50 saved recipients. All settings remain in the protected central
`.config`; mail submission uses the PBX's existing local mail service, without new
provider credentials.

Templates use `delivery.email_recipient_ids`; optional supervisor follow-ups use
`escalation.delivery.email_recipient_ids`. Each is a list of distinct saved IDs
matching `email_` plus 24 lowercase hexadecimal characters, with at most 50 IDs.
Raw addresses are not accepted as incident selectors. These are template fragments,
not complete `start_incident` request bodies:

```json
{
  "delivery": {
    "email_recipient_ids": ["email_aaaaaaaaaaaaaaaaaaaaaaaa"],
    "audio_mode": "none"
  },
  "escalation": {
    "enabled": false,
    "after_seconds": 300,
    "delivery": {
      "email_recipient_ids": ["email_bbbbbbbbbbbbbbbbbbbbbbbb"],
      "audio_mode": "none"
    }
  }
}
```

Replace the example IDs with enabled saved destinations. Enabling escalation also
requires a roster and explicit supervisor destinations; email acceptance does not
count as a human response. The initial and supervisor audiences freeze saved IDs,
exact addresses and sender identity. Changing those identities or disabling the
channel/recipient prevents later incident updates, all-clear or escalation from
retargeting mail. Changes made between incident validation and queue creation are
also rejected. Newly added group members never join an existing incident.

Incident API permissions remain unchanged: a named credential with the required
scope and an unrestricted audience is required. Restricted credentials can use
ordinary `send_announcement` with their permitted `email_recipient_ids`; granting
email IDs does not authorize incident lifecycle operations.

Every separately queued incident announcement has its own original 15-minute email
expiry. An explicit eligible retry keeps that expiry and its original Message-ID;
it does not restart the lifetime. Submission batches have a 90-second budget.
Accepted receipts mean **Accepted by PBX mail service**, not inbox delivery or
human reading. Accepted mail is not resent; uncertain/interrupted submissions are
retained for review and are never automatically replayed. Scheduled drills retain
incident-frozen identities, whereas ordinary announcement schedules resolve saved
recipient IDs when their occurrence becomes due.

## Identity, retries and limits

Every mutating incident request uses a client-generated, 32-character lowercase
hexadecimal `request_id`. Retrying the same operation must reuse that identifier.
The backend rejects conflicting reuse. A durable submission claim precedes job
creation. Interrupted submissions with uncertain job linkage are preserved for
review and never automatically replayed.

Control API incident mutations require a **named, revocable credential** with an
unrestricted audience and the scope below. The originating identity is retained
for scheduled drills/escalation and revalidated at dispatch. Existing legacy-key
announcement/configuration endpoints remain available. The request body cannot
choose its authenticated actor.

Current limits are 2,000 incident records, 250 operations and 4 MiB per record.
Completed history is never automatically deleted. **Incident Workflows > Incident
archival** is an opt-in Labs policy stored in `incident_workflows.retention` in
the central `.config`. The default is off, 90 days after last activity, with a
256 MiB archive allocation. The editor accepts 1–3,650 days and 64–4,096 MiB.
Saving requires Apply Config. The minute worker archives at most ten records in
three seconds per pass. Only old closed incidents or missed drills are eligible;
open/planned incidents and submitting/uncertain operations remain active.

Full compressed reports, original start fingerprints and request identities are
retained in a private SQLite archive, capped at 20,000 reports. A synchronized
archive commit and consistency verification precede removal of an active copy.
Interrupted retirement verifies both copies on its next pass. A bounded cleanup
reclaims leftover cache/lock files only after verifying an archived identity.
The scheduling-worker log includes bounded, explicit policy/storage errors. Conflicting,
damaged, missing or full storage preserves the active report and produces an
error. At least 64 MiB of free workspace is required for archival; allow extra
space for its rollback journal and backup. No pruning renews a request identity.
Duplicate starts return the same archived incident without sending again; archived
reports are read-only. Export and per-job pagination remain available.

`GET resource=incidents&archived=1` reads the archive; omit this field for the
working list. Archive cursors are bound to that view. An archived record adds
boolean `archived` and timezone-aware `archived_at`; existing fields and schema 1
remain compatible. A read exceeding its deadline returns an error rather than an
incomplete success.

Native recovery captures the archive under its permanent locks. Its existing
16 MiB per-file / 64 MiB total operational-evidence bounds still apply: oversized
history fails backup with a clear error rather than silently omitting reports.
Keep a separate protected archive migration/export for larger histories.
Restoration retains full reports as private recovery evidence and merges up to
100,000 permanent blocked incident identities, under the same admission lock as
new starts. No old incidents, escalations or scheduled drills become executable.
Requests for restored identities without a current record are rejected with
recovery guidance. Marker/ledger damage fails closed, and existing live history
is preserved. Reports and replay identities must be retained together during any
offline archive migration.

## Control API routes

Use the existing `/api/sls-mass-notify/` route and authentication headers. GET uses
`resource`; POST uses `action` and a JSON body.

| Method | Resource/action | Scope | Fields |
| --- | --- | --- | --- |
| GET | `incidents` | `read` | Optional `limit` 1–200, opaque `cursor`, `archived=0` or `1` |
| GET | `incident` | `read` | `incident_id` |
| GET | `incident_report` | `read` | `incident_id`, optional `job_offset` and `revision` |
| POST | `start_incident` | `send` | `template_id`, `request_id`, `fields`, boolean `is_test`, optional drill `planned_at`, optional `language_variant` |
| POST | `update_incident` | `send` | `incident_id`, `request_id`, `kind` (`update` or `all_clear`), `message` |
| POST | `incident_roll_call` | `config` | `incident_id`, `request_id`, `person_id`, `response`, optional `note` |
| POST | `incident_checklist` | `config` | `incident_id`, `request_id`, integer `item` (0–24), boolean `complete`, optional `note` |

All these Control API reads require an unrestricted audience. Restricted credentials
cannot inspect another sender's roster/history. Templates are managed through the
authenticated FreePBX UI with CSRF protection.

Reports include the incident snapshot, timeline, human responses and job links.
Follow `next_job_offset` until `complete` is true, passing the first page's `revision`
on subsequent pages. If the incident changes, restart the export. Job receipts are
live evidence and can change during the export; a missing or separately expired
job is identified explicitly. The browser assembles report pages with a 32 MiB limit.

## Desktop self-response routes

- `GET /api/sipnotify/desktop/incident?incident_id=inc_<32hex>` returns a projection
  authorized for the authenticated desktop, not the full roster or administrator
  report.
- `POST /api/sipnotify/desktop/incident/respond` accepts JSON containing only
  `incident_id`, `request_id`, `response` and optional `note` (up to 500 characters).
  Response is `received`, `safe` or `needs_assistance`. The backend binds it to the
  authenticated desktop's assigned roster person. Body fields choosing another
  username, person or actor are rejected. Operator roll call separately permits
  `missing` and records administrator attribution.

These routes reuse per-client Basic authentication, TLS and normal authenticated
traffic limits. Keep posting the existing event acknowledgment when software
receives an announcement. A human response is an explicit, separate action.

## Notification metadata

Incident announcements add this object to the normal published event:

```json
{
  "incident": {
    "schema": 1,
    "incident_id": "inc_0123456789abcdef0123456789abcdef",
    "sequence": 1,
    "kind": "initial",
    "severity": "critical",
    "is_test": true
  }
}
```

Kinds are `initial`, `update`, `all_clear` and `escalation`; severities are
`information`, `warning` and `critical`. Sequence is 1–250. The event's top-level
boolean `is_test` must agree with the incident object. IDs and publication/expiry
timestamps remain stable. Do not infer lifecycle from message wording, display
status or acknowledgment receipt.

## SMS destinations

Templates and supervisor follow-ups also accept `sms_recipient_ids`, with up to 50 distinct saved IDs. Phone number, consent identity and provider route are frozen and rechecked for each incident announcement. New group members never join an existing incident. SMS-only templates can use text-only audio mode. See [SMS setup, permissions and receipt semantics](SMS.md).
