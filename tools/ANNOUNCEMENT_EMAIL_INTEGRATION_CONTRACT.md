# General announcement email sender (F08, source only)

## Invocation and ownership

Invoke the installed `sls_announcement_email.py` using `/usr/bin/python3 -I` as
`asterisk`, with no arguments and one JSON object on stdin. It imports the
same-directory `sls_branded_email.py`. Both files must be in the authenticated
runtime inventory. It reads no `.config`, provider credentials, or delivery
state. Its only submission program is `/usr/sbin/sendmail`; neither command
arguments nor environment can select an alternate program or recipient.

The PHP caller MUST resolve saved recipients and enforce current enabled state,
immutable address fingerprint, originating credential revocation/scope/audience,
and queue expiry immediately before EACH invocation. The worker's existing
activity guard must protect this decision through submission. Never expand a
saved group again or introduce newly added recipients during delivery/retry.

## Input schema

All keys below are required; unknown keys, duplicates, and invalid types fail.
Each invocation submits exactly ONE envelope, with one destination address.

```json
{
  "recipient_id": "email_aaaaaaaaaaaaaaaaaaaaaaaa",
  "address": "recipient@example.com",
  "sender": {"name": "SLS Mass Notification System", "address": "notify@example.com"},
  "title": "Building announcement",
  "message": "Complete plain text, including every line.",
  "is_test": false,
  "severity": "info",
  "message_id": "<sls-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-aaaaaaaaaaaaaaaaaaaaaaaa@example.com>",
  "created_at": "2026-09-23T12:34:56-05:00"
}
```

- `recipient_id`: `email_` plus 24 lowercase hexadecimal characters.
- `address`: one validated ASCII mailbox, maximum 254 bytes; no display names,
  lists, surrounding whitespace, or header injection.
- `sender`: reuse configured sender name/domain/local part. Name is required,
  maximum 320 UTF-8 bytes; address has the same mailbox constraints. Never
  derive sender values from arbitrary API envelope fields.
- `title`: required single line, maximum 1024 UTF-8 bytes.
- `message`: required, maximum 65536 UTF-8 bytes; plain text and escaped branded
  HTML preserve all lines. Tabs and CR/LF allowed; other ASCII controls rejected.
- `is_test`: JSON boolean, not a string or integer. No keyword classification.
- `severity`: exactly `info`, `warning`, or `critical`.
- `message_id`: `<sls-ORIGINAL_DELIVERY_32HEX-RECIPIENT_24HEX@domain>`; recipient
  suffix must match. Generate once when freezing the original request and keep
  it unchanged on eligible retries. The domain must be a normalized DNS domain.
  A stable Message-ID aids diagnosis; it does not guarantee deduplication.
- `created_at`: timezone-aware ISO timestamp (`Z` or `+/-HH:MM`), with optional
  fractional seconds. Freeze it with the original job; used for MIME Date.

Maximum stdin is 131072 bytes, including JSON escaping. Optional fixed-path PNG
branding is read only if a regular single-link nonsymlink file of at most
262144 bytes. Final MIME must not exceed 1048576 bytes. Missing or unsafe logo
assets are skipped. No remote image fetch or attachments supplied by the caller.

## Result protocol

One JSON line on stdout, with no message content, addresses, child stderr,
credentials, or raw exceptions. Delivery results include:

```json
{"ok":true,"state":"accepted","error_code":"","message":"Accepted by PBX mail service. This does not confirm inbox delivery or that a person read it.","retryable":false,"recipient_id":"email_aaaaaaaaaaaaaaaaaaaaaaaa","message_id":"<sls-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-aaaaaaaaaaaaaaaaaaaaaaaa@example.com>"}
```

- `accepted`: sendmail exited zero. Display **Accepted by PBX mail service**.
  Never label delivered, read, or acknowledged by a person.
- `rejected`: no submission accepted. `sendmail_unavailable` is eligible for an
  explicit operator retry after repair; sendmail exit75 is
  `sendmail_temporary_failure` and retryable. Other positive exits are
  `sendmail_rejected` and not automatically retryable. Child diagnostic text is
  discarded; details point the administrator to PBX mail logs.
- `uncertain`: timeout, process signal, or unexpected sender failure. Never
  automatically retry or translate this into a definite failure.

Exit0 means a structured delivery outcome was returned, including rejected or
uncertain. Exit2 is invalid input, with `invalid_envelope`, `rejected`, and
`retryable:false`. Exit1 reports unexpected internal error as uncertain.
Invalid/internal-error responses omit IDs; the caller already has the immutable
expected identity. Reject any returned recipient/message ID mismatch as uncertain.
No stdout, malformed JSON, process kill, or missing confirmation is uncertain.

Fixed command: `/usr/sbin/sendmail -oi -f SENDER -- RECIPIENT`; no shell and no
`-t` recipient extraction. `To` contains only this recipient, with no Bcc.
The child has a five-second deadline and its own process group; timeout kills
that group. Parent CLI supervision should allow cleanup (at least ten seconds).
No automatic attempts or retries occur inside this component.

## PHP durable intent and receipts

Before launching, durably store an email-channel submission intent associated
with the original job/recipient/Message-ID. If that write fails, do not launch.
Afterward, persist the structured result before proceeding to another recipient.
If receipt storage fails after launch, retain the unresolved intent as uncertain;
do not repeat it. Reconcile abandoned intents as uncertain, not pending/retryable.
A job-level failure must not erase accepted receipts or create retries for them.
Map definite rejected outcomes to the existing `failed` receipt state; propagate
`retryable` exactly. Preserve cancelled/changed destinations as explicit rows.

Persist and enforce a bounded per-job email budget in the PHP fanout loop. If
that budget is exhausted before starting a recipient, record a definite
not-submitted result eligible only for explicit retry. Keep checking current
permissions per destination; a single long batch must not bypass revocation.

## Backward compatibility and fixtures

Existing `send_branded_email` callers, weather keyword styling and sendmail
behavior are unchanged. `build_html` gained optional keyword-only rendering
controls; `build_announcement_html` uses explicit metadata and full escaped text.
`tools/test_announcement_email.py` exercises actual MIME plus only private fake
sendmail executables. It never invokes the real MTA, loads production config,
or sends notifications. Existing `test_email_sender_domain.py` also passes.

## Scoped API permission integration

`ApiSecurity::AUDIENCES` includes `email_recipient_ids`; entries must be
`email_` plus 24 lowercase hex characters. An omitted field on existing scoped
credentials normalizes to an empty list. Explicit null is invalid. Existing
secret hashes, IDs, scopes and legacy authentication are unchanged.

`allowedAudience()` returns `email_recipient_ids` from direct grants and current
members of explicitly authorized announcement groups, intersected with valid,
enabled saved recipient IDs. Arbitrary addresses, missing/disabled destinations
and ambiguous duplicate IDs do not grant email permission. This function does
not itself assert global channel availability or freeze addresses: the caller
must still check `announcement_email.enabled` and the original destination
fingerprint immediately before submission.

`permitsResolvedAnnouncement()` validates a maximum50 saved email IDs and
requires them to fall within scoped permissions. Unrestricted/legacy keys retain
access to saved IDs, subject to the caller's availability/fingerprint checks.
Nonempty raw `emails` or `email_recipients` fields are rejected for all keys;
these were never supported general-announcement channels. The credential editor
projects only enabled valid saved IDs and escaped names, not email addresses.
Existing credential creation delegates validation to `ApiSecurity::issue()`;
no new endpoint is needed.

`tools/test_api_email_permissions.php` covers48 actual PHP authorization/render
checks, including old credentials, group removal, revoked keys, malformed
resolved payloads, unrestricted/legacy behavior, and private-data projection.

## Incident templates and frozen recipients

`IncidentConfig::delivery()` accepts up to50 distinct `email_recipient_ids` for
both primary and supervisor escalation delivery. Missing fields normalize to[];
raw addresses and malformed selectors are rejected. The incident facade expands
saved-group email members once and stores only exact address/sender fingerprint
hashes in `_identities.email_recipient_ids`. Updates, all-clear and escalation
compare the original hashes against current enabled targets before queueing.
Old records lacking the new field/map remain compatible only with an empty
email audience; normalization does not rewrite their history or invent hashes.

The facade holds a shared announcement activity lease across current identity
revalidation and the main facade's durable queue snapshot. Configuration
replacement takes its exclusive counterpart. Incident record locks are released
before dispatch; announcement-send locking is nonblocking, so this does not
introduce a blocking cycle with installer send/activity lock ordering. The lease
is released on all return/exception paths. Queue workers still perform their own
current recipient/credential checks before individual submissions.

Incident UI selectors contain saved IDs and escaped names only. Export and
receipt guidance distinguishes local-MTA acceptance from inbox delivery/human
response. `tools/test_incident_email.php` exercises real configuration, facade,
email fingerprints, durable IncidentService/Store, actual separate-process lock
contention and exception release using private fixtures. The ordinary incident
regression fixture gained inert activity-lock adapters; no production code runs.
