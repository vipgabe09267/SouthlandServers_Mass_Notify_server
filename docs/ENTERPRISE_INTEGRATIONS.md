# Enterprise Labs device and provider adapters

These opt-in adapters start disabled: `enterprise_integrations.enabled`, every section's `enabled`, and `public_warning.ipaws_enabled` are `"0"`. Settings, credentials and signing material use the existing protected central AES config. Saving and rendering the administrator editor send no device or provider requests. No additional packages are installed by these adapters.

The Weather Alerts switch controls NWS delivery and manual Weather tests. Ordinary notifications, enrolled triggers and Labs adapters use their own saved enablement and the SLS runtime switch. Stopping SLS blocks new notifications and physical actuation. Authorized delivery receipts, participant responses, sensor heartbeats, status queries, read-only device telemetry and local CAP validation/export remain available; their current identity and feature permissions still apply. Lightning has its own enablement.

Qualification is limited to isolated API, HMAC, TLS, signature, replay and DTMF fixtures. No real speaker, sensor board, controller, OAuth account, carrier incident reply or FEMA account was available. The Arduino sketches have not been compiled or exercised on hardware. Device firmware, physical results, provider permissions and authorized FEMA CDTE acceptance remain pending. This does not claim managed mustering, visitor/badge/camera management, geofencing or device certification.

## Speakers and physical evidence

The five saved speaker profiles represent SIP-capable products, without a market-share ranking:

| Brand | Representative model | Primary setup information |
| --- | --- | --- |
| Algo | 8180 IP Audio Alerter | [Algo guide](https://docs.algosolutions.com/v1/docs/8180-ip-audio-alerter-user-guide) |
| CyberData | 011394 SIP Speaker | [CyberData product/manual](https://www.cyberdata.net/products/011394) |
| Axis | C1511 Network Ceiling Speaker | [Axis audio APIs](https://developer.axis.com/vapix/audio-systems/) |
| Valcom | VIP-120A-V4 | [Valcom product](https://www.valcom.com/vlc-product/ip-8-inch-round-ceiling-speaker-one-way-vip-120a-v4/) |
| AtlasIED | IP-SDM | [AtlasIED product](https://www.atlasied.com/ip-sdm) |

Enroll each device as an existing PJSIP extension and configure paging/auto-answer according to its firmware's vendor instructions. The Labs speaker action dispatches TTS through the existing announcement queue, normal PBX audience admission and per-extension delivery evidence; the queued request keeps the resolved extension audience. SIP acceptance, call answer, playback completion, device-reported test results and explicit human responses remain distinct evidence. No acceptance or playback status proves that an announcement was heard or that a device displayed text.

Save the exact model, firmware and acceptance notes with each profile. Verify idle, busy, offline and multiple-contact behavior on that device. These profiles do not supply a generic multicast transmitter or automatic vendor provisioning.

Only Axis has a physical telemetry adapter in this release: read-only `axast:GetSpeakerTestReport` at `/vapix/axast`, returning the last documented test status and timestamps. It never calls calibration or `PerformSpeakerTest`. A saved HTTPS origin, pinned RFC1918 IPv4, matching trusted TLS certificate and authorized device account are required. [Axis test API](https://developer.axis.com/vapix/audio-systems/auto-speaker-test-service-api/)

## Bounded door operations

The Axis VAPIX adapter uses `/vapix/doorcontrol` with the saved controller `Token`. Each door has explicit `allow_read`, `allow_lock`, `allow_unlock` and `allow_access` permissions. Actuation permissions default false and require the central `access_control_actuation` Labs acknowledgement. A controller account must enforce the same device permissions.

`read`, `lock` and `unlock` use `tdc:GetDoorState`, `tdc:LockDoor` and `tdc:UnlockDoor`. Persistent unlock is separate from temporary access. For `access`, SLS first reads `tdc:GetDoorList` with a limit of 100, requires exactly one matching token, and verifies the device's `AccessTime` against `maximum_access_seconds` (1–60 seconds, default 10). A missing, ambiguous or excessive timer rejects actuation. SLS then calls `tdc:AccessDoor`; the controller owns relocking, so it does not depend on a delayed PBX command.

No controller provisioning, schedules or identities are exposed. Command acceptance and controller-reported state do not prove the physical door's state. Verify relocking and actual sensor feedback on the exact firmware, including when the PBX disconnects. [Axis door API](https://developer.axis.com/vapix/physical-access-control/door-control-service/)

## OAuth incident meetings

An administrator selects an incident and supplies a fixed start time, duration and stable request ID. Authorized OAuth access tokens are saved centrally. Initial application registration, consent and token acquisition remain external; SLS supplies no bundled credentials or invitation service.

| Provider | Exact creation contract | Required creation scope |
| --- | --- | --- |
| Zoom | `POST /v2/users/{user}/meetings`, scheduled type 2, waiting room enabled | `meeting:write:meeting` or applicable admin scope |
| Google Meet | `POST https://meet.googleapis.com/v2/spaces`, `config.accessType=RESTRICTED` | `https://www.googleapis.com/auth/meetings.space.created` |
| Webex | `POST https://webexapis.com/v1/meetings`, title/start/end | `meeting:schedules_write` |

Google Meet creates a restricted space; the supplied coordination start/duration do not create a Calendar event. Receipt links are restricted to the provider's HTTPS join hosts. Host-start URLs, host keys and tokens are excluded from receipts. [Zoom creation API](https://developers.zoom.us/docs/api/meetings/), [Meet spaces.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create), [Webex meetings](https://developer.webex.com/meeting/docs/meetings)

Optional renewal uses `oauth_refresh_enabled`, `oauth_client_id`, `oauth_client_secret`, `oauth_refresh_token` and absolute Unix timestamp `oauth_expires_at`. Renewal stays disabled until explicitly enabled with complete credentials. Before meeting creation, a configured token expiring within 60 seconds is renewed using the selected provider's fixed HTTPS token endpoint. The response expiry and any rotated refresh token are saved atomically to encrypted configuration. An administrator can also request one explicit renewal. A known expired access token is rejected when renewal is unavailable.

Refresh attempts are durably claimed before contacting the provider. An interrupted or uncertain rotating-token request is not automatically repeated; review the provider authorization and save replacement credentials. Revocation first disables local meeting access and removes its access/refresh tokens. Zoom and Google revocation is then attempted at their documented endpoint; Webex app authorization must be revoked in its provider portal. Provider renewal/revocation and real meeting creation still require account-specific acceptance.

## ntfy and Gotify notifications

`enterprise_integrations.connectors` holds at most 20 saved destinations. Each has `id`, `name`, `enabled`, `provider`, `origin`, `pinned_ipv4`, `token` and `topic`. IDs use `conn_<24 hex>`. Use a self-hosted HTTPS origin, trusted matching certificate, pinned private IPv4 and scoped token. ntfy requires a topic and uses Bearer authentication; Gotify requires an application token in `X-Gotify-Key` and an empty topic. Tokens never enter request URLs or queue snapshots. Public ntfy hosting is not supported by this private-address adapter.

Select explicit saved destinations in Devices and providers, or submit `connector_ids` through an unrestricted named Control API sender. The normal announcement queue freezes each destination's configuration fingerprint and checks current authorization before submission. Changed or disabled destinations cannot receive an old queued alert. Accepted provider message IDs appear in the `connector` delivery row; this is distinct from client display or human receipt. Uncertain submissions are not automatically replayed.

Verified browser Web Push uses the same queued selection flow with `browser_recipient_ids`; its enrollment, encryption and permissions are documented in [Enterprise identity](ENTERPRISE_IDENTITY.md). See [ntfy publication](https://docs.ntfy.sh/publish/) and [Gotify messages](https://gotify.net/docs/pushmsg) for service configuration.

## Explicit participant responses

Each saved response route maps a frozen incident roster person to existing SMS and/or voice recipients. A recipient cannot represent two people. Enrollment freezes incident, person snapshot, saved target, provider route, number hash and config revision; a unique 12-hex incident code expires after the configured TTL. Revocation, changed routes, incident closure, unknown numbers and ambiguous matches reject responses.

Signed Twilio/Telnyx inbound text must match `SLS <CODE> SAFE`, `SLS <CODE> HELP` or `SLS <CODE> RECEIVED`. Existing callback signature validation occurs before the response hook. Provider event identity is durable and idempotent. Plain `SAFE` is not guessed. STOP stays an opt-out; replies require saved consent newer than the stored STOP timestamp. BulkVS has no verified inbound signature contract here and remains refused.

Opt-in voice metadata passes through existing immutable outbound snapshots and PBX admission. After successful normal audio playback, the local AGI proves the bound answered call and current participant binding, then reuses the bundled acknowledgement prompt offering key 1. Key 1 records `received`; timeout/other keys/call answer produce no human response. An existing enabled keypad acknowledgement is reused without a second prompt. With Labs response flags disabled, normal audio has no added prompt or helper call.

## Enrolled sensor examples

Examples under `slsmassnotifyserver/examples/sensors/` cover Raspberry Pi GPIO, ESP32 and Arduino MKR WiFi 1010/Nano 33 IoT. They are disabled by default and use only an approved isolated dry-contact input. They do not connect to, alter or supervise a fire panel. Enroll an existing sensor trigger and use its saved literal 64-character secret; do not hex-decode that key.

The exact signature input is UTF-8 `rule_id + "." + timestamp + "." + raw_body`, HMAC-SHA256, sent as hex `X-SLS-Signature` and decimal `X-SLS-Timestamp`. HTTPS trust and hostname verification are required, and redirects are refused. The JSON body's stable `request_id` is 32 lower-case hex digits; pending activation bytes and expiry are preserved across retries. Local contact debounce is 50 ms, a held startup input establishes a baseline, and local activation cooldown is 10 seconds. Server enrolled-trigger replay protection/cooldown still apply.

Heartbeat JSON contains only `operation:"heartbeat"`, `request_id`, integer `sent_at`/`expires_at`, and boolean `is_test`. Existing HTTPS/HMAC authentication must run before `receiveSensorHeartbeat`. Heartbeats neither open incidents nor send announcements. Duplicate/backward heartbeats cannot renew source health. Source freshness does not prove sensor accuracy.

The Pi client requires both config `enabled:true` and `--send`; keep its enrollment config and durable state private. ESP32 requires reviewed Wi-Fi, CA and enrollment values plus `ENABLE_SEND=true`; pending state uses Preferences. Arduino requires the PBX issuer in WiFiNINA's certificate store, a unique reviewed `DEVICE_ID`, explicit one-time `INITIALIZE_NEW_JOURNAL`, then that initialization flag must return false. Corrupt/uninitialized journals fail closed. Heartbeats do not rewrite Arduino flash sequence storage. Inspect pending expired activations against server history; never erase replay evidence to force a retry.

Optional client dependencies are not vendored or installed: gpiozero BSD-3-Clause; Arduino-ESP32 core LGPL-2.1 (framework crypto retains its upstream notices); WiFiNINA LGPL-2.1-or-later; ArduinoBearSSL MIT; FlashStorage LGPL-2.1-or-later. Preserve upstream licenses for any separately built/distributed firmware. [gpiozero license](https://github.com/gpiozero/gpiozero/blob/master/LICENSE.rst), [ESP32 license](https://github.com/espressif/arduino-esp32/blob/master/LICENSE.md), [WiFiNINA notice](https://github.com/arduino-libraries/WiFiNINA/blob/master/src/WiFiSSLClient.h), [ArduinoBearSSL license](https://github.com/arduino-libraries/ArduinoBearSSL/blob/master/LICENSE), [FlashStorage notice](https://github.com/cmaglie/FlashStorage/blob/master/src/FlashStorage.h)

## CAP export and authorized IPAWS bridge

The bounded CAP 1.2 editor defaults to Test and Public scope, requires a current sent time, expiry within 24 hours, affected-area text and a closed polygon or explicit SAME geocodes. Update/Cancel requires original same-sender references. Export transmits nothing. [OASIS CAP 1.2](https://docs.oasis-open.org/emergency/cap/v1.2/CAP-v1.2-os.html)

The local acceptance action accepts only a Test or Exercise draft and its matching signed `post_envelope`. It validates structure, configured authority and signatures, returning `cap_sha256`, `envelope_sha256` and `authority_qualification: "pending_external_acceptance"`. Nothing is transmitted. Passing this check does not replace authorized FEMA CDTE or channel acceptance.

IPAWS origination separately requires saved `ipaws_enabled`, authorized COG confirmation, the exact issued FEMA HTTPS service endpoint and WSDL SOAPAction, current matching RSA certificate/key, and `public_warning_origination` Labs acknowledgement. There is no supplied authority, certificate, account, WSDL or channel permission. The authorized integration provides a matching WSDL-generated signed postCAP SOAP envelope; SLS verifies the configured COG identity, exact reviewed CAP payload, exclusive canonicalization/SHA256/RSA256 signatures and unique message IDs before submission. This Labs bridge currently accepts an inline CAP alert with one alert signature and one SOAP-body signature. Other WSDL representations are not guessed and remain unsupported until authorized qualification.

A documented signed getAck envelope builder is available for authorized integration qualification. Provider responses retain bounded channel status codes, which do not prove public dissemination or receipt. FEMA authority must validate its issued WSDL, channel requirements, CAP signature representation and CDTE workflow before use. [FEMA IPAWS-OPEN interface guide v4.02](https://content.govdelivery.com/attachments/USDHSFEMA/2023/11/17/file_attachments/2687711/IPAWS-OPEN-v4-02-InterfaceDesignGuide_Draft_11172023.pdf)

## Durable claims and maintenance

External meeting, door-actuation and IPAWS operations claim a stable 32-hex request identifier before any effect. Reusing it with changed input rejects; completed/uncertain operations do not automatically resend. When cluster HA is enabled, the claimed creation time and immutable details feed the existing witness effect wrapper. Invalid/unavailable private storage fails closed.

Direct browser and manual Weather tests cannot establish notification-HA witness authority. Browser tests reject cluster-enabled deployments; manual Weather tests reject HA, edge or witness roles before any channel begins. Use queued, witness-authorized announcement tests for those deployments. Standalone configuration mirroring does not prevent the manual Weather test.

Preserve `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-integrations/worker-state.json`, its `announcement-send.lock` initialization marker and the parent `.enterprise-integrations-required` marker in private operational backups. Missing established state or malformed records reject. Active capacities are 500 provider operations, 2000 participant bindings, 4000 reply events and 100 enrolled sensor-health rows; the UI reports capacity warnings at 80 percent.

Administrator-initiated archival accepts `retention_days` from 1–365, default 7. It archives old terminal provider operations and completed responses before compacting the active journal; pending and uncertain operations remain active. Expired response bindings are removed, while response and request replay evidence remains retained. Archives use private `archive/operations.json` and `archive/responses.json`, each bounded to 4 MiB and 20,000 records, with per-ledger `.required` and parent `.archive-required` markers. Reports merge active and archived evidence. A missing archive, conflicting identity or exhausted allocation requires reviewed recovery; exporting a report does not authorize deleting replay evidence.

## Administrator actions

These are the authenticated FreePBX administrator dispatcher actions, using the existing CSRF-protected `payload={action,input}`. They are not unrestricted public Control API endpoints.

| Action | Input | Effect |
| --- | --- | --- |
| `integrations_labs_send` | `{browser_recipient_ids,connector_ids,title,message,is_test}` | Queues explicitly selected enabled destinations; test defaults true. |
| `integrations_oauth_refresh` | `{}` | One durably claimed provider renewal. |
| `integrations_oauth_revoke` | `{}` | Disables local access, clears tokens and attempts supported provider revocation. |
| `integrations_door` | `{request_id,door_id,operation}` | Saved permitted `read`, `lock`, `unlock` or timed `access`; actuation requires danger acknowledgment. |
| `integrations_continuity` | `{}` | Configuration-only channel dependencies and local recovery readiness; no network or outage test. |
| `integrations_report` | `{}` | Active and archived operation/response evidence, without authentication fingerprints. |
| `integrations_archive` | `{retention_days?}` | Safe bounded archival; preserves uncertain work and permanent replay evidence. |
| `integrations_cap_validate` | `{warning,post_envelope}` | Local Test/Exercise validation; sends nothing. |

Run deployment-specific checks in [Enterprise acceptance](ENTERPRISE_ACCEPTANCE.md). These controls do not establish hardware, carrier, provider or authority qualification.

Isolated validation commands:

```sh
php tools/test_enterprise_integrations.php
php tools/test_enterprise_sms_replies.php
python3 tools/test_enterprise_integration_protocols.py
php tools/test_enterprise_integrated_facade.php
php tools/test_enterprise_provider_lifecycle.php
php tools/test_enterprise_announcement_channels.php
php tools/test_enterprise_journal_maintenance.php
php tools/test_enterprise_continuity.php
```

These tests use fake provider responses, temporary protected journals, generated fixture certificates, TLS MemoryBIO without sockets, signed SMS fixtures and fake AGI calls. They never send alerts or act on devices. The facade fixture executes actual mainclass CSRF/trigger methods, administrator traits, locked saves, frozen delivery snapshots and IncidentService against temporary storage. Existing SMS, BulkVS, outbound voice, route and phone-admission regressions were also exercised. The isolated real Asterisk playback regression passed with disposable Local calls and synthetic carrier-answer events; it made no external call.
