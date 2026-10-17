# Qualification boundaries

`0.1.7-beta` is an unpublished candidate. These entries describe isolated local
checks and installer admission rules. They do not certify a production deployment.

## Local browser qualification — 2026-10-17

All six browser suites ran with the pinned Playwright/Chromium runtime in private
mount, network and PID namespaces. They covered subscriber-worker CSP, separate
IdP navigation, operator and Labs screens, calendars and recordings, per-admin
beta warnings, 1,000 scheduled browser recipients and voicemail controls.
Desktop and mobile layout assertions passed. The tests used synthetic settings
and inert transports; they did not change the PBX or contact real providers.

The first run exposed two fixture setup errors: an advertised SSO callback that
did not match the private HTTPS origin, and a calendar-name test whose parent
editor was closed. Both fixtures were corrected and passed. Production origin
checks and text escaping were retained.

The SSO fixture also releases its private mounts before later suites. The
35-case legacy-permission suite, including native FreePBX permission handling,
then passed without skips. Missing browser dependencies fail all required
browser gates, including voicemail controls.

## Installer profiles

| Condition | Admission and evidence |
| --- | --- |
| Debian 12 / FreePBX 17 / amd64 / CPython 3.11 | Supported adapter, standard paths and `asterisk` runtime identity. |
| Apache PHP / PHP-FPM | Served extensions and routes inspected. Private FPM with timestamp checks disabled verifies cached-code replacement after reload, including an active request with the default process-control timeout. FPM can close active web connections during reload; uninterrupted requests are not guaranteed. Complete FPM PBX VM qualification remains required. |
| Forwarded HTTPS ports / proxies / IPv6 | Explicit advertised ports retained; trusted-proxy policy and TLS authority checks enforced. |
| Split filesystems / tmpfs | Free space and inodes checked on actual destination filesystems; unsuitable persistent storage rejected. |
| Containers / cgroups | Effective resource limits measured; namespaces and atomic replacement capabilities required. |
| Asterisk includes / explicit loading | Includes and templates parsed without executing `#exec`; unavailable or excluded providers rejected. |
| Recognized legacy permissions | Exact known ownership, type, links, size and modes checked before recovery; unknown changes require review. |
| Standard FreePBX web modes | Root/Asterisk-owned integration files with the Asterisk group retain `0775/0664` modes in static snapshots and rollback. Root runtime stays private; foreign groups and world writes are rejected. Read-only inspection covers the complete static inventory. |
| Fresh / 0.1.4 / 0.1.5 / 0.1.6 baselines | VM controller supports these inputs; no complete new VM matrix has run for this candidate. |
| Other OS, architecture, paths or identity | Unqualified. Unknown executable layouts are not automatically adopted. |

Use `sudo slsconsole doctor --json` for read-only prerequisites, release identity
and recovery locations. Use authenticated installer `--inspect` for upgrade
inventories. Repair is a separate explicit action.

## Delivery and integrations

| Integration | Local checks | External acceptance still required |
| --- | --- | --- |
| Desktop polling / SSE | Private Apache HTTPS, enrollment authorization, receipts, bursts, revocation, heartbeat and disconnect cleanup. | Public-edge routing, current app and sustained full deployment capacity. |
| Phones / voicemail | Private Asterisk, AMI admission/outcomes, vendor payloads and dialplan fixtures. | Each handset/firmware, full playback, auto-answer and mailbox delivery. |
| External calls | Routing, answer-gated playback, admission and receipt fixtures. | Carrier, selected trunk/DID, audio quality and keypad behavior. |
| Postfix email | Bounded admission, initialization recovery and failure fixtures. | Relay configuration and actual inbox receipt. |
| Twilio / Telnyx SMS/MMS | Request/response, signed callbacks, STOP, consent and spending fixtures. | Provisioning, approved sender and live handset/opt-out round trip. |
| BulkVS SMS/MMS | Outbound requests, diagnostics and API acceptance fixtures. | Live routing and provider-managed opt-outs. Authenticated inbound callbacks remain unsupported; unsigned callbacks are rejected. |
| OIDC / SAML — Labs | Assertions, state/replay, revocation, browser redirect and local TOTP flow. | Configured IdP, tenant policy and recovery process. |
| Browser subscribers — Labs | Proof, audience authorization, push deduplication and actual worker registration under CSP. | Push service, permissions, expiry and device display. |
| Speakers / access control — Labs | Bounded adapters, capabilities, claims, failures and uncertain outcomes. | Exact API/firmware and physical effects. HTTP acceptance does not prove playback or door state. |
| Zoom / Google Meet / Webex — Labs | OAuth, refresh, idempotency and callbacks. | Credentials, tenant permissions and actual meeting lifecycle. |
| Failover / remote sites — Labs | Lease, witness, replay, mirror and local delivery fixtures. | Separate PBXs, partitions, power loss, fencing and recovery. Experimental failover must not be used on production servers. |
| Sensors — Labs | Signed source admission, pending/expiry handling and local SDK compilation for ESP32, MKR WiFi 1010 and Nano 33 IoT. | Flash/power-loss, clock/TLS and isolated-contact hardware trials. |
| CAP / IPAWS — Labs | XML bounds, selected-language context, identity, expiry and cancellation. | Reviewed feeds, authorization and actual public-warning bridge. |

Restricted operators and named API senders may target only their explicitly
permitted browser subscribers and connectors. Saved audiences retain enrollment
and configuration bindings; queued jobs recheck current permission and identity.
Existing operator accounts receive no implicit grants. Incident language cohorts
and scheduled delivery preserve these recipients. Existing announcement and
desktop schemas remain compatible.

Native FreePBX archives contain recovery keys: protect the complete archive with
FreePBX encryption and restricted storage. Operational snapshots preflight size
and stream within fixed bounds. Oversized or unfinished journals fail clearly
without omitting evidence.
