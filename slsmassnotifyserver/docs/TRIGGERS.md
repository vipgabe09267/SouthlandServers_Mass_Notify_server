# Triggers and actions — Labs

**Mass Notify → Triggers and Actions** connects an explicitly enrolled source to
a saved incident template and/or a reviewed device/script action. Configuration,
enrollment secrets, routing and script approvals live in the central `.config`.
New entries start disabled. Saving sends nothing; Apply Config activates them.
Use Incidents to prepare the message, field bindings and six-channel audience:
phones, desktops, external calls, email, SMS and webhooks.

Up to 100 triggers and 50 actions are supported, with at most ten actions per
trigger. The durable journal admits at most 500 events and preserves cancellation,
expiry, revocation and uncertain outcomes. Every input carries an explicit test
flag and a stable identifier. Source, routing or device changes invalidate queued
work; automatic processing never silently replays an uncertain command.

## Panic sources

Assign a saved location, message/template and permitted actions. Enrollment
generates a per-source secret, shown once. Rotating enrollment revokes the old
secret. The HTTP protocol requires a deliberate confirmation: request a
challenge, review its trigger/location, wait at least two seconds, then activate
within its 60-second validity. A replay of the same accepted request returns the
same event without repeating the action.

An optional internal dial-in shortcut supports explicitly listed PJSIP callers.
Choose an unused extension and configure its confirmation prompt. Apply Config
prepares speech. The caller must press 1 to activate; hanging up or sending the
wrong key does not activate it. This is distinct from group paging, whose PIN
and caller-number rules are documented in [Paging](PAGING.md). No external panic
shortcut or desktop panic button is enabled implicitly. A desktop panic UI
requires matching app support for this contract.

## Signed sensor and panic HTTP contract

Endpoint: `POST /api/sls-mass-notify/trigger.php?rule_id=trg_<24 hex digits>`.
It requires verified HTTPS and `Content-Type: application/json`. Requests are
bounded to 16 KiB. Do not put the enrollment secret in the URL or Basic auth.

Set `X-SLS-Timestamp` to the current ten-digit Unix timestamp and
`X-SLS-Signature` to lowercase hexadecimal HMAC-SHA256. The signed bytes are:

```text
rule_id + "." + timestamp + "." + exact_raw_JSON_body
```

Use the enrollment secret's literal 64-character hexadecimal string as the HMAC
key. The request clock must be within 60 seconds. JSON fields for a sensor are:

```json
{"operation":"activate","request_id":"device-event-0001","sent_at":1800000000,"expires_at":1800000300,"event":"door","message":"Reviewed sensor detail","is_test":false}
```

Replace the example timestamps and event name. The event must exactly match the
saved rule; the receiver cannot select recipients, paths or commands. Panic first
sends `{"operation":"challenge","request_id":"<32 hex digits>"}`. Activation
sends `operation: "activate"` plus the event fields and returned `confirmation`.
The response contains `ok: true` with a `challenge`, or the stable `event_id` and current `state`. Keep the accepted
request identifier across retries. `429` reports cooldown; `503` means completion
could not be confirmed, so inspect history before any new activation.

## Emergency-call observation

Choose exact internal PJSIP caller extensions, exact dialled numbers, a saved
location and responder template. No emergency number, route or audience is
assumed. The existing AMI phone-event collector passively observes matching call
events and never changes, delays or originates the emergency call. It rejects
unrelated/external caller evidence and deduplicates each correlated call. This
needs acceptance with the deployment's actual trunk/dialplan; fixtures are not
a real emergency-call test.

## CAP feeds

Configure a trusted public HTTPS URL, exact CAP sender and exact event name.
The parser accepts CAP 1.2 XML or an Atom feed with inline CAP records, at most
50 alerts and 1 MiB. Linked-only entries require a direct CAP URL. External
entities, document types, redirects, private destinations, mismatched sender,
unsupported scope, stale and expired alerts are rejected. Minute polling uses
a 300–600 second configured freshness window. Test/Exercise events require the
explicit Allow test events setting. Update/Cancel must reference earlier records
from the same sender; cancellation blocks pending work and preserves evidence.

## BrightSign and PATLITE

BrightSign UDP sends an exact, saved presentation event to a private unicast IPv4
player and configured port. Configure the matching UDP input/event and display
content in its presentation. Submission is not proof of player receipt.

PATLITE NHV uses `/api/control` over configured HTTP or verified HTTPS, with an
explicit clear command or five LED digits (`0` off, `1` steady, `2` flashing).
Configure the tower's supported API and private unicast address. Redirects are
refused. HTTP 200 confirms endpoint acceptance, not physical light operation.
Adapters do not discover devices, upload presentations or assume compatibility
with other models. Physical devices remain Labs until tested.

## Approved scripts

Select an absolute `.sh` or `.js` file and explicitly approve its current SHA-256.
The file and every parent must be root-owned, unwritable by group/others, without
symbolic links; the file must be a single-link regular file of at most 256 KiB.
Scripts run as the PBX runtime account, never root, using fixed `/bin/bash` or
`/usr/bin/node`. They receive bounded event JSON on stdin and no configured
secrets. Execution uses sealed reviewed bytes, a restricted environment,
`no_new_privs`, resource/output limits and a ten-second deadline. Changed bytes
require a new approval. Scripts are trusted administrator code, not a sandbox
for untrusted code: they can perform actions allowed to the PBX runtime account.
