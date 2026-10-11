# Enterprise Labs acceptance

Enterprise Labs features start disabled. Protocol fixtures exercise local contracts; they do not certify a provider account, speaker, access controller or public-warning authority. Use an isolated test deployment for outage, failover and physical actuation exercises. Keep the danger acknowledgement required for experimental identity, mirroring/failover, door actuation and public-warning origination.

## Browser Web Push

1. Configure verified browser subscribers, the public HTTPS subscriber URL and a `mailto:` or HTTPS VAPID contact. Generate the VAPID key once; it stays in encrypted `.config`. Preserve it with configuration backups.
2. Save a subscriber tied to an existing incident roster person before starting that incident. Send a verification invitation, open its one-time link, then grant browser notification permission and enroll the browser. No public signup is provided.
3. Enable browser push explicitly. In Devices and providers, select that subscriber for a marked test announcement. Inspect that announcement's `browser_push` delivery row. Push-service acceptance is distinct from display and from a human response.
4. Verify foreground/background display in your supported browsers, notification-permission denial, local browser removal, credential/identity revocation, and expiry. Test Apple mobile-browser behavior separately; no native mobile application is supplied.
5. Repeat the original event request: an endpoint must receive it at most once. Enroll a second browser after queuing an event: that browser must not receive the older event. Replace an enrollment key: older snapshots must not authorize the replacement.

Payloads use RFC 8291 encryption and RFC 8292 VAPID. Supported browser-service endpoints are limited to documented Google, Mozilla, Apple and Microsoft origins. Announcements expire within ten minutes. Cloud push requires Internet connectivity; browser/OS policy can suppress display.

## ntfy and Gotify

Use a self-hosted HTTPS origin, valid certificate, pinned private IPv4 and a scoped token. ntfy requires a topic and Bearer token; Gotify uses an application token in `X-Gotify-Key`. Tokens never enter request URLs or queue snapshots. Save the destination disabled, enable it explicitly, then select it for a marked queued test. Verify accepted message IDs in the provider and delivery rows. Test invalid tokens, unavailable services, certificate failure, duplicate execution and changed destination configuration. Uncertain submissions are not automatically replayed.

Provider contracts: [ntfy publication](https://docs.ntfy.sh/publish/), [Gotify message API](https://gotify.net/docs/pushmsg).

## Meeting OAuth lifecycle

Configure an authorized Zoom, Google Meet or Webex application with meeting permissions. Supply the selected provider's access token, absolute expiry, client ID/secret and refresh token before enabling renewal. The refresh response must contain a bounded bearer token and expiry; replacement refresh tokens are saved atomically with encrypted configuration.

Test token expiry, one explicit refresh, provider-issued rotation, an interrupted refresh, and local revocation. An uncertain rotating-token request is fenced from automatic replay; obtain replacement authorization before another attempt. Revocation disables local meeting access before contacting the provider. Webex app authorization must also be revoked in the provider portal. SLS creates coordination meetings/spaces, not Calendar invitations or confirmed attendance.

Contracts: [Zoom OAuth](https://developers.zoom.us/docs/integrations/oauth/), [Google offline access](https://developers.google.com/identity/protocols/oauth2/web-server#offline), [Webex authentication](https://developer.webex.com/create/docs/authentication).

## Directory and roster changes

Keep immutable external IDs. Map LDAP account-control flags explicitly; Active Directory's disabled bit is `2`. Configure SCIM core/enterprise JSON pointers rather than inferring identity from mutable display names. Missing or malformed activity attributes, ambiguous primary email, partial paging and changing result counts must reject the import.

Run a dry run for each complete snapshot. Review additions, updates and deactivations. Large removal percentages require a separate confirmation; a preview is one-use, expires after fifteen minutes and rejects concurrent configuration changes. Applying a snapshot changes future rosters and subscribers, while queued announcements and existing incident rosters remain frozen. Importing directory data grants no operator privilege.

## Speaker and physical-device qualification

For each saved speaker, record the exact brand, model, firmware and local acceptance notes. Register its existing PJSIP extension and configure vendor auto-answer. Verify complete opening tone, speech and closing tone; check idle, busy, offline and multiple-contact cases. Axis's read-only last-test report is device evidence, not confirmation that a person heard an announcement. Other brands use the normal PBX audio evidence path.

For an Axis door, save the exact permitted token and allowed operations. Prefer **timed access** when temporary access is intended: SLS reads the controller's `AccessTime`, rejects an unknown/ambiguous/excessive timer, and uses the device's `AccessDoor` operation. Device firmware owns relocking. Confirm the physical door and controller telemetry before and after the operation, then repeat with the PBX disconnected. Permanent unlock is a separate explicit permission. No fire-panel supervision or life-safety certification is claimed.

Contract: [Axis door control](https://www.developer.axis.com/vapix/physical-access-control/door-control-service/).

## Offline operation and notification failover

Use **Review channel continuity** for a configuration-only list of channel dependencies and enrolled local recovery administrators. That report opens no connection and measures no outage. Test local password/TOTP recovery while the IdP/WAN is isolated in a separate lab. Local phone/speaker delivery requires running Asterisk, current collector health, registered contacts and local audio assets. Desktop delivery needs a reachable certificate-valid PBX origin. SMS, browser push, meetings, new weather data and many carrier routes depend on external connectivity.

Notification HA requires independently operated peers and the reviewed witness/fencing design. Exercise one-way and total partitions, witness outage/restart, lost replies, stale authority, peer reboot, duplicate immutable jobs, bounded offline jobs, credential revocation and manual failback. Preserve uncertain effect evidence; a takeover must not repeat an uncertain call, SMS, webhook or physical command. Matching SLS settings do not replicate trunk state, active calls, voicemail or all FreePBX configuration. This is not complete PBX failover qualification.

## CAP and IPAWS

Author a **Test** or **Exercise** CAP draft first. The local acceptance button validates the draft and matching signed postCAP SOAP envelope without transmitting it. Record the returned SHA-256 values with the authority's review. This proves local structure/signature checks only.

Actual IPAWS acceptance requires an authorized organization/COG, current authority-issued certificate/key, the issued WSDL/SOAPAction, permitted areas/channels, and successful FEMA CDTE exercises. Test alerts, updates, cancellations, references, expiry, wrong authority, signature changes, duplicate IDs and uncertain acknowledgements. Do not enable production public-warning submission on the strength of fixture tests or the local validation button.

## History and replay protection

Completed integration operations/responses and reviewed drills/approvals can move to private archives through the Labs UI. Pending and uncertain work stays active. Reports include live and archived evidence. Archive writes precede live compaction; missing archives and conflicting IDs fence further replay instead of initializing empty history.

Each private archive ledger is bounded to 4 MiB and 20,000 records; active provider and coordination journals have smaller bounds shown by their controls. Preserve archives with operational backups and review capacity warnings. Exporting a report does not authorize deleting replay records, consent or spending evidence. An exhausted or damaged ledger requires reviewed recovery, not an empty replacement.

Control API callers may supply `Idempotency-Key` for ordinary announcements. A key is scoped to the authenticated credential and fixed request/permissions. Successful repetitions return the original queued result; conflicting content returns HTTP 409. An interrupted claim never resends automatically. Claims retain this protection for thirty days; review history before intentionally creating a new request identity.
