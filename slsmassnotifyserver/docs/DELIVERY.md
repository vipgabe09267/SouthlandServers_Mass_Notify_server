# Delivery behavior and troubleshooting

Service acceptance, software receipt and human acknowledgment are separate
outcomes. Failed channels do not suppress independent destinations.
Only confirmed failed, eligible destinations can be explicitly retried;
interrupted or uncertain submissions are not automatically replayed.

## Audio, voices and previews

Fresh defaults are Lessac for announcements, Amy for Weather/Lightning and 25%
volume. Announcements include opening and closing paging tones. Weather and
Lightning have their own opening tones and no default closing tone; any tone
can be set to None. Telephone audio is 8-kHz mono PCM with one second of leading
silence. The answer window defaults to five seconds and can be set to one–five
seconds, separately from visual expiry.

The catalog supports US English, Spanish (Spain), French, German and Brazilian
Portuguese. Supplied text is synthesized, never translated. External calls can
select a separate Lessac medium voice or follow the announcement voice; both
choices are frozen when submitted. Carrier bandwidth still limits call quality.
Speech above the configured duration limit is rejected rather than cut off.

The Dashboard previews phone images and internal/external speech without
contacting recipients. Speech previews omit tones and show the complete spoken
text. Changing text invalidates an older preview. Up to 50 literal pronunciation
rules use `phrase = spoken replacement`, case-insensitive whole-phrase matching,
longest first, without cascading replacements. Rules affect speech only and
are frozen with queued work.

System Recordings supports search and pagination and reports incomplete scans.
Audio preparation checks cached files before reuse; corrupt regular caches are
rebuilt, while linked or special files are rejected. Ordinary page loads do not
scan recordings or checksum full voice models. Synthesis verifies the selected
model and metadata against pinned checksums.

## Phone admission and visual messages

Announcements, Weather, Lightning and paging share a phone admission ledger.
It freezes PJSIP contacts, rejects oversized audiences and allows a bounded wait
for occupied capacity. Calls retain slots until a complete channel inventory
confirms they ended. Missing collector evidence or an incomplete inventory
blocks new SLS audio.

The `sls-mass-notify-phone-events` service collects outcomes. Use **Refresh phone
outcomes** in delivery details. Answer, conference entry and hangup show channel
activity, not full playback or human receipt. Collector faults are nonfatal
during ordinary FreePBX reload/Apply Config, but installation and restore
readiness remain strict.

Vendor SIP NOTIFY formats are selected per registration when safe contact routing
is supported, with a generic endpoint fallback otherwise. Unknown phones may
ignore generic XML. UDP, TCP and TLS contact syntax is preserved.
See [provisioning and phone formats](../PHONE_FORMATS.md).

Visual delivery allows four senders, twelve seconds per sender and ninety seconds
for the display phase. Desktop publication and bounded webhook delivery precede
it. Results distinguish accepted submission, an uncertain started sender and one
that never started. Asterisk acceptance cannot confirm handset display.
Live-page SIP text follows the selected recipients and General Settings timeout;
text failure does not stop authorized live audio.

### Internal voicemail

**Weather Alerts > Weather Alert Audio** and **Lightning Alerts > Alert Audio**
have independent **Leave voicemail for unanswered extensions** controls.
**Dial-in Paging > Paging access** has **Record live pages for voicemail**.
They start enabled; upgrades preserve disabled choices. Save and Apply Config.

Weather/Lightning deposits use complete prepared audio, including a configured
all-clear. Live pages are recorded and deposited after the caller finishes.
At most one message is deposited per selected extension and page when none
of its selected handsets answered. The caller is excluded. Offline live groups
can receive voicemail through eligible mailboxes; with live voicemail disabled,
at least one receiving phone must be registered.

For registered phones, a missed call requires the exact handset's `DialEnd`
result or consistent terminal AMI state with an explicit busy/no-answer/unavailable
cause. This includes Page timeouts that omit `DialEnd`. Local-channel answers,
end hooks and ambiguous hangups cannot authorize fallback. Interrupted outcome
collection also blocks fallback; answered handsets prevent deposit.

Asterisk's native voicemail application uses existing mailbox storage,
message-waiting indication and notification policies. SLS creates no mailboxes
and cannot bypass length/capacity rules. Ineligible or failed deposits retain a
specific result. Mailbox storage is separate from SLS media cleanup.

Manual Weather/Lightning tests and saved channel checks do not leave voicemail.
Ordinary Dashboard TTS and external-number calls are unchanged; carrier greeting
or beep detection is not implemented. Validate actual mailbox delivery using an
explicitly approved internal recipient. A saved message does not prove listening.

### External calls · Labs

External voice starts disabled. Saved international recipients can be selected
on the Dashboard, in groups and schedules. The default uses FreePBX outbound
routes; a specific PJSIP trunk and caller-ID/DID override can be selected.
A DID supplies caller ID and does not create an inbound route. Carrier and
forced-trunk policies still apply.

Only reviewed FreePBX route profiles are supported. The loaded dialplan is checked
before submission and dialing. Exact reviewed CRM, missed-call, allowlist and
route-email callbacks are allowed; changed callbacks, route PINs, recording hooks,
unknown call-producing hooks and incompatible trunk Dial overrides are rejected.
Vendor changes may require a new profile. SIP forwarding is suppressed only for
the SLS call; ordinary PBX/trunk settings are unchanged.

Each external recipient requires its own matching trunk-answer evidence before
tones or speech start. Local-channel or another recipient's answer is insufficient.
Missing/interrupted evidence withholds playback without automatic redial.
Carrier or voicemail answer does not establish human receipt.

Optional keypad acknowledgment asks the answered recipient to press **1** after
complete playback. Wait time is 5–30 seconds, default 10. The included English
prompt needs no live synthesis. Playback and key results bind to the exact call;
wrong keys, timeout and interruption remain distinct. The option starts disabled
and does not change internal audio.

The optional `outbound_voice.daily_call_limit` accepts 0–10,000; zero leaves SLS
daily admission unlimited under simultaneous-capacity controls. Each admitted
external recipient reserves one call before submission. Failed/uncertain attempts
are not refunded. The durable counter resets at 00:00 UTC, survives compaction
and rejects clock rollback or corrupt state without resetting itself.
It counts SLS admissions, not ordinary PBX calls or prior history.
Budget rejection preserves eligible internal audio. Native restore disables
external voice pending review because prior call usage may be absent.

### Audio journals

All producers and cleanup share a permanent reservation lock and synchronized
atomic journal replacement. Install these helpers together with the bundled
installer, which drains workers and bridges the old/new lock during migration.
Empty/corrupt journals, unsafe paths or loss of an initialized journal block audio
or cleanup. Preserve journals, locks and logs for review rather than deleting
them or restoring older delivery state.

## Desktop delivery

The [reviewed app at c9baa8e](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_app/tree/c9baa8eaf11fa1bd524dd7fcaa07ae4484ab25aa)
uses live-only delivery. Each SSE connection starts at the current tail;
messages missed while disconnected, asleep or shut down are skipped.
After stalled streams or HTTP 429, it honors `Retry-After` and uses five-second
JSON snapshots. Initial/recovery snapshots establish a baseline without replay.

| Route | Purpose |
| --- | --- |
| `/api/sipnotify/desktop/stream` | Authenticated SSE with approximately 15-second heartbeats. |
| `/api/sipnotify/desktop` | Uncached JSON polling with an HTTP Date header. |
| `/api/sipnotify/desktop/ack` | Durable receipt for the exact event and desktop identity. |

All routes enforce recipients. Duplicate acknowledgments retain their original
timestamp. Successful replies include `ok: true` and matching `event_id`;
receipt-storage failure returns retryable HTTP 503 with `Retry-After`.
Results reconcile exact event receipts even when an acknowledgment precedes the
saved job result. **Received by desktop app** needs no Dismiss click and does not
mean someone read it. Old jobs without an event ID cannot be reliably reconciled.

Snapshots return up to 100 eligible events when requested, with `eligible_count`
and `window_truncated`. Forward pagination uses `last_event_id`, `has_more`
and `cursor_gap`. The reviewed app uses snapshots; more than 100 relevant
publications between polls are not guaranteed by that fallback.
IDs, timezone-aware publication times and absolute expiry stay stable across reads.
The app applies a ten-minute maximum, or an earlier server expiry, even when server
display timeout is zero. Expiry does not erase job results or receipt history.

Schema 1, SSE protocol 2, authentication and endpoint paths remain compatible.
All producers emit boolean `is_test`; wording does not activate incident workflows.
Updates, all-clear, human responses and panic/enrollment require explicit contracts
and matching client support. Desktop version/readiness headers are additive and
the advisory minimum-version policy never blocks delivery.
See [desktop readiness](DESKTOP_READINESS.md).

SSE disables compression/output buffering, flushes events and heartbeats and
releases slots on exit. Verify it through the actual public HTTPS/proxy route;
authentication alone does not establish ongoing stream activity.

Colored desktop images are unique 1440×816 PNGs, limited to 5 MiB, served over
HTTPS on the app's PBX origin including a validated forwarded port. They remain
anonymously readable because the current app sends no image credentials/cookies.
Complete plain text remains available for Details/fallback. Phone images remain
480×272 with their own transport settings.

## Email, SMS and webhooks

Announcement email starts disabled. Configure Postfix and up to 50 named saved
recipients under **General Settings > Delivery**, enable the channel, then Save
and Apply Config. Sender fields do not configure a relay. Email-only announcements
can use **Audio: None**. Existing upgrade routes are preserved.

Each recipient receives separate text and branded HTML through local Postfix.
**Accepted by PBX mail service** confirms local submission, not inbox delivery.
A bounded `postqueue -j` check stops SLS mail at 200 queued messages or when
queue state is unverifiable. Shared admission limits are 120 requests and 1,000
recipients per minute, 1,000 requests and 5,000 recipients per hour, and 5,000
requests and 25,000 recipients per day. System mail is limited to 2/10/20
requests and 100/500/1,000 recipients over those windows. Account mail,
including password recovery, is limited to 10/100/500 requests and the same
10/100/500 recipient counts. One request can contain at most 50 recipients.
Other PBX mail is unaffected. Cron output goes to bounded diagnostics rather
than email.

Submitted announcements freeze exact recipient IDs/addresses and sender identity.
Email keeps its original 15-minute expiry and a 90-second batch budget.
Explicit eligible retries do not renew expiry or resend accepted addresses;
interrupted/uncertain handoffs are never automatically replayed.
For API email selection and permissions, see [Control API](CONTROL_API.md)
and [incident email destinations](INCIDENT_API.md#email-destinations).

Phone and SMS recipients share an editor with independent Call/SMS choices and
recorded consent. Twilio/Telnyx enforce verified STOP callbacks; BulkVS supports
API acceptance but not authenticated inbound delivery/STOP callbacks.
**Check saved BulkVS sender** checks sender/campaign setup without sending.
See [SMS/MMS setup, costs and receipts](SMS.md).

Webhook formats include native JSON, Discord, Slack and Teams Workflows.
Teams supports secret-URL workflows with the **Anyone** trigger; authenticated
tenant/OAuth triggers are not implemented. Oversized payloads are rejected.
Typed incidents include a protected HTTPS SLS incident link.
See [collaboration contracts](COLLABORATION.md).

Accepted and unconfirmed one-way webhooks do not raise a delivery fault solely
because human receipt is unavailable. Explicit HTTP rejections or failures before
transmission need attention. Unknown submissions are never automatically replayed.
Discord uses packaged `webhook-builder.png` as a small footer icon, with a versioned
PBX HTTPS URL and configured public Control API port. Discord must reach that route.

## Weather, scheduling and current faults

Weather emails include the spoken announcement, affected areas, full NWS description
and protective instructions. New observations continue while frozen queued alerts
are delivered in order, with expiry/cancellation/deduplication checks.
Speech uses official instructions when supplied; generic protective advice is
used only when the alert lacks instructions. Invalid enabled quiet-hour times
suppress noncritical delivery, while explicitly selected critical events retain
their override.

Weather features remain bounded to 256 KiB each. Larger map geometry is carried
through private input streams with the full instructions and alert identity,
avoiding operating-system argument limits. Invalid or oversized data is
rejected before submission; speech duration and complete email body limits
still apply.

Legacy line-based Weather history is bounded to 5,000 records and 2 MiB per file.
Startup preserves existing records, including Heat Advisories. It refuses new
delivery at capacity, unsafe files and missing history behind a required marker;
it never deletes records merely to reopen admission. Preserve recovery exports
and review the affected history before retrying. An explicit manual replay is
separate from automatic deduplication.

Weather/Lightning workers can run disjoint audiences concurrently; shared
recipients and area state remain ordered. Admitted work expires no later than
3,200 seconds after starting or one hour after initial queueing.
Already submitted playback is not cut off and uncertainty is not replayed.

For a forecast zone, use [NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone):
combine the state abbreviation, `Z` and three-digit number, such as `TXZ163`.
Adaptive lightning polling follows current alerts/forecast periods and provider
quotas. Weather.gov outages default to Standby after usable data and grace expire.
Optional five-minute fallback polling lasts 15–120 minutes, default 30, using a
durable per-area deadline that restarts/settings changes cannot renew.
Missing weather coverage is not clear and never creates an all-clear.
See [alert setup and configuration](../INSTALL.md).

Scheduling supports finite reviewed series up to five years, PBX-local time,
calendar exceptions and a 1–15-minute maximum start delay.
Disjoint audiences can proceed independently; late unstarted work is skipped,
active calls continue and original deadlines survive recovery.
See [scheduling behavior and recovery](SCHEDULING.md).

Saved channel checks provide up to ten phone/desktop test profiles using announcement
settings and cooldown. They never send email/webhooks, query Xweather or deposit
voicemail. Save and Apply Config before running a profile.

System Overview shows completed delivery failures as recent warnings for 15 minutes
while preserving their records. A newer successful job or matching recovery clears
the current warning. Disabled/removed features stop contributing stale faults.
Collector, storage, polling and installation faults reflect current checks;
external uncertainty and lost audit evidence remain for review.

Worker probes older than ten minutes and storage health older than five minutes
are stale. Stable fault identities and shared limits bound fault email.
Deployment readiness checks runtime, queues, clock, local HTTPS and resources;
device-acceptance records save administrator observations, not automated proof.
See [readiness and separate-host monitoring](DEPLOYMENT_READINESS.md).

A queued announcement is not finished until its worker result is recorded.
To check bootstrap/job storage without sending:

```bash
php /usr/local/bin/sls_mass_notify/sls_mass_notify_announcement_worker.php --health-check
```

Control API audit failures are separate from accepted action results. Do not resend
an accepted announcement just because `X-SLS-Audit-Status: unavailable` is returned.
Preserve interrupted audit-cleanup evidence for review; see
[audit forwarding and recovery](AUDIT_FORWARDING.md).

## Hostnames, forwarded ports and media

SLS does not automatically follow DNS or the operating-system hostname.
Use **General Settings > Phone Delivery > Change advertised address** to set the
hostname and external desktop/API/image ports, then check local HTTPS, Submit and
Apply Config. A local probe verifies the local origin, not public DNS/NAT/proxy
reachability. Certificates, firewall, email domain and desktop connections need
separate review.

The configured image address may include a forwarded port without changing
Apache listeners, SIP transport or distribution defaults. Phones must reach it
and validate its certificate. Optional generated-media network restrictions and
absolute download expiry start disabled and preserve desktop image compatibility.
See [media access and trusted proxies](MEDIA_ACCESS.md).

Trust only reverse proxies you administer, with exact configured IPv4/IPv6 CIDRs
and overwritten forwarding headers. Leave the list empty for direct connections.
Malformed/missing information from a trusted proxy is rejected; forwarded hostnames
are never trusted. Credential/proxy management requires the authenticated PBX admin.
