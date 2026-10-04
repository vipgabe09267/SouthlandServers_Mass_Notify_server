# SMS announcements

This feature belongs to the unpublished 0.1.5-beta candidate. The adapters and
failure paths have isolated tests; a real provider account, callback route and
carrier still need acceptance testing. SMS is disabled by default. Saving settings
or previewing text does not contact a provider.

## Configure

In **General Settings > Channels and providers > SMS alerts**, choose **Twilio**, **Telnyx** or **BulkVS** and an
SMS-capable sending number from that account, in `+countrycode` format. Enter an
organization label, which precedes the announcement title and complete message.
All settings, credentials and saved recipients remain in `mass-notifications.config`.
Blank secret fields retain saved values; the Clear checkbox removes a credential.
**Check saved BulkVS sender** performs a read-only lookup of the sending number.
It identifies disabled SMS and missing A2P long-code campaign assignment without
sending messages or changing the provider account. Account-wide SMS enablement
does not mean that each number is enabled. HTTP 409 delivery details retain a
bounded, redacted provider reason when BulkVS supplies one; credentials, phone
numbers, URLs and full provider responses are not included.
The Control API's configuration response redacts SMS secrets.
Sending fields stay disabled until a provider is selected. Switching providers
retains drafts in the form; saving blank secret fields preserves saved credentials.

- Twilio needs the account SID, an API key SID and secret for sending, and the
  account auth token for verifying callbacks.
- Telnyx needs an API key, messaging profile ID, and account Ed25519 webhook public
  key. Configure a short outgoing queue lifetime in the provider profile.
- BulkVS needs its API username and password. SLS submits one recipient per
  HTTPS request using Basic authentication and BulkVS's documented
  `POST /api/v1.0/messageSend` schema. Sender and recipient use country-code digits
  without `+` on that provider's wire format; saved configuration remains `+` format.
The response must match the exact sender and recipient and contain a valid RefId.

**BulkVS currently reports API acceptance only.** Its published API specification
does not define authenticated delivery or inbound opt-out callbacks. SLS does not
accept unsigned callbacks or invent signature rules. Manage opt-outs in BulkVS,
disable affected SLS recipients before another send, and verify the provider account
before live use. Automatic delivery updates and inbound STOP handling for BulkVS
remain unsupported pending a verified callback contract. Its outbound adapter has
isolated tests; live provider/carrier acceptance remains pending. See the
[BulkVS API documentation](https://portal.bulkvs.com/api/v1.0/documentation) and
[published OpenAPI schema](https://portal.bulkvs.com/api/v1.0/openapi).

The UI shows the canonical callback URL derived from the saved advertised HTTPS
Control API address. Forwarded HTTPS ports are supported through that existing
setting; distribution defaults do not contain an installation-specific port.
For Twilio and Telnyx, configure inbound messaging webhooks at that address for STOP handling. SLS adds
an individual delivery callback URL to each outbound request. Callback validation
uses the saved address, not untrusted proxy or Host headers. Confirm the provider
can reach the configured port and presents valid callbacks before relying on it.

Save up to **50 recipients**, each with a unique number, Enabled, recorded Consent,
and a note describing when/how they agreed. Consent timestamps are assigned by
the UI's server-side save. Verified Twilio/Telnyx STOP callbacks block future sends. The settings page
shows blocked recipients; recording renewed consent requires an updated note.
The provider may separately require START. An inbound START cannot clear a local
block merely by replaying an old signed request.

Set a budgeted cost per segment, account currency, daily/monthly cost limits and
segment limits before enabling. Defaults allow 3 segments per message, 100 per
UTC day and 1,000 per UTC month; cost limits default to 10 and 100 currency units.
Cost per segment starts unset. Use a value accounting for applicable provider and
carrier charges, and also set limits in the provider account. SLS reserves the
configured amount; it does not determine or reconcile the provider's final bill.

## Select and preview

Twilio and Telnyx messages append **Reply STOP to opt out.** Their authenticated
inbound callbacks enforce the local opt-out. The footer is part of the frozen
message, segment count and budget; an over-limit message is rejected in full.
BulkVS does not receive this promise while its inbound integration is unsupported.
Its sending number also needs SMS enabled and an approved messaging campaign.

A Twilio `21610` rejection also records an opt-out, even when the original STOP
webhook never reached the PBX. The result and block are committed together;
duplicate callbacks cannot overwrite a later consent timestamp. The recipient
must opt in at the provider and an administrator must record renewed consent in
SLS before another alert is eligible. See [Twilio's opt-out error](https://www.twilio.com/docs/api/errors/21610).

The dashboard, saved announcement groups, schedules and incident templates can
select saved SMS recipient IDs. SMS-only announcements use Audio: None. **Preview
SMS** shows the complete text, GSM-7 or Unicode encoding, segment count, budgeted
cost per recipient and whether the segment limit is exceeded. Text over the
configured limit is rejected, never silently cut. Display-image styling does not
change SMS text. Drill/test messages carry an explicit test label.

Numbers, consent identity, provider route, body and original submission deadline
are frozen at Send. Dispatch rechecks current enabled recipients, consent and
sender permissions. It cannot redirect a queued message to an edited number or
new group member. Each job has a 15-minute SMS submission deadline; a schedule's
earlier start deadline takes precedence. This is a **PBX submission deadline**:
carrier delivery can occur later. Twilio receives the remaining validity period;
Telnyx queue lifetime also depends on provider configuration. BulkVS has no documented
outbound TTL field; the local deadline does not guarantee a carrier expiry. Phone and desktop
display expiry are separate.

SMS is available for general announcements and incident workflows. Weather and
lightning routes can explicitly select saved SMS recipients and retain the same
consent, immutable audience, quiet-hours, expiry and provider checks. Mobile push,
IP speakers, visitor enrollment and failover remain excluded/deferred.

## Receipts, limits and recovery

A durable SQLite claim and both spending reservations commit before the HTTP
request. Concurrent workers share the same limits. Calls use fixed provider API
hosts, certificate verification, bounded bodies and timeouts, and no redirects.
An accepted, timed-out or uncertain request is **never automatically resent**.
Failed and uncertain submissions retain their reservations. A batch has a bounded
90-second window; recipients not started in time have explicit failed receipts.

For Twilio and Telnyx, signed callbacks update the exact local/provider message identity. Early receipts
survive the send response, and duplicates/out-of-order progress do not erase
terminal evidence. Conflicting terminal results are marked uncertain. Callback
storage failures return HTTP 503 with Retry-After so the provider can retry.
“Delivered” means the provider reports delivery, not that a person read the text.
Reopening job delivery details refreshes carrier receipts; audit/job data stays
separate from the provider ledger. Secrets and complete provider error bodies
are not copied to public errors.

`PLUGIN_DATA_DIR/sms` holds operational state, not configuration: a private SQLite
ledger, initialization marker and lock. Missing/corrupt/unsafe established storage
blocks sending; do not remove it to clear an error. Delivery claims are retained
for 90 days. Accepted inbound opt-out callback identities remain so an old signed
STOP replay cannot renew suppression after new consent. Both inventories are
capped at 100,000; reaching a bound requires reviewed ledger maintenance rather
than silently forgetting replay protection. Opt-out tombstones and cost
reservations remain. No SMS sends occur during maintenance or restore.

Native FreePBX backups include a portable SMS data snapshot when a ledger exists.
Restore merges records without deleting newer live claims, reservations or
opt-outs. Unioned deliveries cannot reduce counters; identity/currency conflicts
reject the merge. Pending records become uncertain and are not replayed. SMS is
disabled after restoring an enabled configuration until an administrator reviews
consent, routing and continuity. Protect the whole backup: it contains the central
configuration's secrets. A configuration-only export is not a delivery-state backup.

## API additions

Use `sms_recipient_ids: ["sms_aaaaaaaaaaaaaaaaaaaaaaaa"]` at the top level of
`send_announcement` or in its existing options object. Raw numbers are rejected.
Named credentials require explicit saved-ID or saved-group authority. Older
restricted credentials have no implicit SMS authority. Queued jobs recheck grants.

Incident templates use `delivery.sms_recipient_ids` and supervisor follow-ups use
`escalation.delivery.sms_recipient_ids`. Existing incident API authorization still
applies. Receipt rows add `channel: "sms"`, `sms_delivery_id`, provider message ID,
segments, configured reserved cost and currency. These additions do not change
notification payload schema 1, SSE protocol 2 or existing endpoint paths.

The public callback is POST `/api/sls-mass-notify/sms-callback.php`, optionally with
one server-generated `delivery` query parameter. Twilio/Telnyx use provider signatures;
BulkVS callbacks are rejected. Signature checks use the published provider contracts,
not the Control API key. Unsigned requests are rejected before PBX bootstrap or
writable receipt storage. The pinned Twilio SDK validator's MIT license and source
provenance ship under `api/sls-mass-notify/sms/vendor/twilio` and appear in the SBOM.

## MMS — Labs

Twilio, Telnyx and BulkVS support **MMS · alert image and text** in General
Settings → Delivery providers → SMS and MMS alerts. SMS remains the default.
Enabling MMS at the provider does not switch the SLS format automatically. Set a
budgeted MMS price in the account currency, including provider and carrier fees.
Amounts show two decimal places for whole values and retain sub-cent precision.
MMS is not necessarily cheaper: compare one MMS price against the SMS segment
count and carrier fees for the same complete text.

MMS attaches a 1440×816 summary PNG (at most 300 KB) and retains the complete
message text, up to the existing 1,600-character messaging limit. Review uses
private frozen bytes. A worker publishes a unique image only after recording a
consented recipient's durable claim and reserving its cost. Each MMS counts as one
sending unit; each SMS segment counts as one. Daily/monthly unit and spending
limits apply to both. Failed/uncertain claims retain reservations and are never
resent automatically. Failure to publish the image produces a failed receipt
without contacting the provider; MMS does not silently switch to SMS.

Providers must be able to GET/HEAD the image over the advertised PBX HTTPS origin,
including a configured forwarding port. The sender must be MMS-enabled and
registered where required. Generated-media network/age restrictions still apply;
allow verified provider fetches before using restrictive rules. Images have random
versioned URLs and remain in the cache for at least 24 hours. They contain alert
summaries, so do not use this channel for confidential content. TLS and provider
credentials are verified; arbitrary media URLs and redirects are not supported.

Provider requests use Twilio `MediaUrl`, Telnyx `media_urls` and BulkVS `MediaURLs`.
Existing `sms_recipient_ids` and channel `sms` remain compatible; receipt rows add
`message_type: "MMS"` and the per-message reservation. Poll/SSE schemas stay
unchanged. Signed Twilio/Telnyx receipts and opt-outs work for both formats.
BulkVS acceptance remains unconfirmed handset delivery; its inbound STOP/receipt
webhook is still unsupported and opt-outs must be managed at the provider and in
SLS recipients. No paid/provider delivery is claimed by fixture tests.

MMS ledger evidence is included in continuity V2 backups. Current code also reads
legacy V1; SMS-only snapshots remain V1. Older candidates cannot restore V2 MMS
snapshots. Restore never repeats an SMS or MMS, rewinds reservations or removes
newer opt-outs. Generated attachment images are an expiring cache, not a replay
queue.
