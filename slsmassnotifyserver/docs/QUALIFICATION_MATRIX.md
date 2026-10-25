# Qualification boundaries

`0.1.8-beta` is a published prerelease. These entries describe isolated local
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
| Fresh / 0.1.4 / 0.1.5 / 0.1.6 / 0.1.7 baselines | VM controller supports these inputs; no complete new VM matrix has run for this release. |
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

## 0.1.8 qualification boundary

The October 23 private Chromium checks also exercised the actual browser-display
PHP page and CSP, authenticated and legacy image downloads, absolute expiry,
calendar month/week/list edits, save-state cancellation, 320-pixel layouts and
200/400-percent CSS zoom. These checks do not replace an assistive-technology or
physical-device acceptance review.

| Area | Source/private-fixture evidence | Acceptance still required |
| --- | --- | --- |
| Desktop polling | Two-worker private FPM, 25/100/250/500/1,000 unique five-second clients, same public IP; cursor bursts, current authority and control-probe latency | Sustained actual FreePBX dashboard, calls and storage workload on each supported host |
| Passkeys | Real library signatures, origin/RP/UV/challenge/revocation and private HTTP login cases | Physical authenticators and chosen browser/platform |
| Optional regional voices | Pinned native CPU synthesis to private audio files; duration and nonzero sample checks | Local pronunciation and handset/gateway playback quality |
| Radio | Profile authorization, immutable bindings, complete-audio rejection, existing phone admission | Real SIP gateway/PTT/RF/channel release and legal authorization |
| Cloud directories/collaboration | Bounded payload, TLS, paging and reviewed-import fixtures | Credentialed tenant/provider behavior, quotas and key rollover |
| Restricted scripts | No-fallback admission, command/mount/resource validation | This host denies unprivileged user namespaces; positive execution remains unqualified. Requires target-host kernel/bubblewrap acceptance; not arbitrary-code certification |
| Browser | Event-bound proofs, live-only display, revocation and private JavaScript/HTTP tests | Actual browser push-service delivery and visual accessibility with assistive technology |
| Full installer lifecycle | Independent failure, trust, rollback, legacy-mode and filesystem fixtures; disposable VM workflow included | Fresh and 0.1.4/5/6/7 upgrades on full PBX VMs, interruption/retry and offline uninstall |
| Paired-host failover, door control, public origination | Existing disabled Labs contracts and danger acknowledgments | Hardware/provider credentials, partitions, fencing and local recovery; do not use in production |

The repeated fleet fixture used six five-second polling waves per tier. Its
1,000-client sample measured about 399 ms poll p95 and 380 ms concurrent
control-probe p95. Summed worker RSS was about 101 MiB and CPU approached two
cores. State used private tmpfs and FastCGI sockets, without the production TLS
route or a real PBX workload; shared pages can be counted twice in summed RSS.
These short measurements establish neither physical-storage throughput nor a
complete PBX resource recommendation. All external acceptance rows remain open.

Manual review claims can be checked with
`python3 tools/check_audit_ledger.py /path/to/review-ledger.json --root /path/to/source`.
It rejects pending passes and changed source hashes; it never marks a review
complete. A passing ledger covers only its declared inventory, not an unlisted
file or every possible defect. Retain inherited open reviews separately.
