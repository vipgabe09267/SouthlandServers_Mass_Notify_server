# Enterprise Labs device and provider adapters

These opt-in adapters start disabled: `enterprise_integrations.enabled`, every section's `enabled`, and `public_warning.ipaws_enabled` are `"0"`. Settings, credentials and signing material use the existing protected central AES config. Saving and rendering the administrator editor send no device or provider requests. No additional packages are installed by these adapters.

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

Enroll each device as an existing PJSIP extension and configure paging/auto-answer according to its firmware's vendor instructions. The Labs speaker action dispatches TTS through the existing announcement queue, normal PBX audience admission and per-extension delivery evidence; the frozen request retains selected profile identity. SIP acceptance, call answer, playback completion, device-reported test results and explicit human responses remain distinct evidence. No acceptance or playback status proves that an announcement was heard or that a device displayed text.

Only Axis has a physical telemetry adapter in this release: read-only `axast:GetSpeakerTestReport` at `/vapix/axast`, returning the last documented test status and timestamps. It never calls calibration or `PerformSpeakerTest`. A saved HTTPS origin, pinned RFC1918 IPv4, matching trusted TLS certificate and authorized device account are required. [Axis test API](https://developer.axis.com/vapix/audio-systems/auto-speaker-test-service-api/)

## Bounded door operations

The Axis VAPIX adapter uses `/vapix/doorcontrol` with `tdc:GetDoorState`, `tdc:LockDoor` or `tdc:UnlockDoor`, each containing only the saved controller `Token`. Every door has explicit `allow_read`, `allow_lock` and `allow_unlock` permissions. Lock/unlock default false and require the central `access_control_actuation` Labs acknowledgement. No controller provisioning, schedules, identities or other door commands are exposed. A controller account must enforce the same device permissions. `UnlockDoor` is persistent until another applicable controller command; it is not a temporary access pulse. Controller command acceptance is not a verified physical lock state. [Axis door API](https://developer.axis.com/vapix/physical-access-control/door-control-service/)

## OAuth incident meetings

An administrator selects an open incident and supplies a fixed start time, duration and stable request ID. Authorized OAuth access tokens are saved centrally; there are no bundled credentials, automatic token acquisition or invitations. The operator's authorized OAuth application renews expired tokens.

| Provider | Exact creation contract | Required creation scope |
| --- | --- | --- |
| Zoom | `POST /v2/users/{user}/meetings`, scheduled type 2, waiting room enabled | `meeting:write:meeting` or applicable admin scope |
| Google Meet | `POST https://meet.googleapis.com/v2/spaces`, `config.accessType=RESTRICTED` | `https://www.googleapis.com/auth/meetings.space.created` |
| Webex | `POST https://webexapis.com/v1/meetings`, title/start/end | `meeting:schedules_write` |

Google Meet creates a restricted space; the supplied coordination start/duration do not create a Calendar event. Receipt links are restricted to the provider's HTTPS join hosts. Host-start URLs, host keys and tokens are excluded from receipts. [Zoom creation API](https://developers.zoom.us/docs/api/meetings/), [Meet spaces.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create), [Webex meetings](https://developer.webex.com/meeting/docs/meetings)

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

IPAWS origination separately requires saved `ipaws_enabled`, authorized COG confirmation, the exact issued FEMA HTTPS service endpoint and WSDL SOAPAction, current matching RSA certificate/key, and `public_warning_origination` Labs acknowledgement. There is no supplied authority, certificate, account, WSDL or channel permission. The authorized integration provides a matching WSDL-generated signed postCAP SOAP envelope; SLS verifies the configured COG identity, exact reviewed CAP payload, exclusive canonicalization/SHA256/RSA256 signatures and unique message IDs before submission. This Labs bridge currently accepts an inline CAP alert with one alert signature and one SOAP-body signature. Other WSDL representations are not guessed and remain unsupported until authorized qualification.

A documented signed getAck envelope builder is available for authorized integration qualification. Provider responses retain bounded channel status codes, which do not prove public dissemination or receipt. FEMA authority must validate its issued WSDL, channel requirements, CAP signature representation and CDTE workflow before use. [FEMA IPAWS-OPEN interface guide v4.02](https://content.govdelivery.com/attachments/USDHSFEMA/2023/11/17/file_attachments/2687711/IPAWS-OPEN-v4-02-InterfaceDesignGuide_Draft_11172023.pdf)

## Durable claims and maintenance

External meeting, door-actuation and IPAWS operations claim a stable 32-hex request identifier before any effect. Reusing it with changed input rejects; completed/uncertain operations do not automatically resend. When cluster HA is enabled, the claimed creation time and immutable details feed the existing witness effect wrapper. Invalid/unavailable private storage fails closed.

Preserve both `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/enterprise-integrations/worker-state.json` and `announcement-send.lock` in private operational backup/restore. The latter carries `SLS_ENTERPRISE_INTEGRATIONS_V1` as a durable initialization marker. Missing established state or malformed records reject; no automatic pruning discards replay or participant evidence. Journal capacities are bounded at 500 provider operations, 2000 participant bindings, 4000 reply events and 100 enrolled sensor-health rows. Review retention before these bounds are reached.

Isolated validation commands:

```sh
php tools/test_enterprise_integrations.php
php tools/test_enterprise_sms_replies.php
python3 tools/test_enterprise_integration_protocols.py
php tools/test_enterprise_integrated_facade.php
```

These tests use fake provider responses, temporary protected journals, generated fixture certificates, TLS MemoryBIO without sockets, signed SMS fixtures and fake AGI calls. They never send alerts or act on devices. The facade fixture executes actual mainclass CSRF/trigger methods, administrator traits, locked saves, frozen delivery snapshots and IncidentService against temporary storage. Existing SMS, BulkVS, outbound voice, route and phone-admission regressions were also exercised. The isolated real Asterisk playback regression passed with disposable Local calls and synthetic carrier-answer events; it made no external call.
