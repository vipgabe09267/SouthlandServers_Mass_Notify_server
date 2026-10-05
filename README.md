<p align="center">
  <a href="https://southlandservers.xyz"><img src="https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/slsmassnotifyserver/assets/SLS_Mass_Notif_Plugin.png" width="112" alt="Southland Servers"></a>
</p>

# SLS Mass Notifications Server

Phone, desktop, weather, lightning, and scheduled notifications for FreePBX 17 / Debian 12. AGPL-3.0-or-later. Version `0.1.5-beta`.

The 0.1.5-beta prerelease includes the repaired older-release upgrade path, faster Enterprise Labs account lookup and dashboard redirection when its warning is cancelled. Existing 0.1.5-beta installations must rerun the current signed installer to receive this same-version repair. See [authenticated installation and recovery](INSTALL.md#authenticated-installation-and-recovery).

## Enterprise Labs

Optional enterprise tools are disabled by default and configured in **Enterprise Labs** inside the FreePBX administrator panel. The operator portal receives only assigned operational actions. Existing phone, desktop, SMS, email and webhook delivery continues through the existing SLS queues.

- [Continuity and remote sites](docs/ENTERPRISE_CLUSTER.md): notification peers, witness fencing, configuration mirroring and offline local delivery.
- [Identity and subscribers](docs/ENTERPRISE_IDENTITY.md): OIDC/SAML, reviewed directory synchronization and verified browser recipients.
- [Devices and providers](docs/ENTERPRISE_INTEGRATIONS.md): SIP speakers, sensors, door controls, meetings, two-way responses and public warnings.
- [Incident coordination](docs/ENTERPRISE_OPERATIONS.md): private floor plans, drills, shift audiences and two-person approval.

Enterprise Labs automatically opens the requested danger notice on the first visit. The checkbox unlocks after five seconds; acceptance is saved in the encrypted configuration and does not enable any feature. Later visits do not repeat the notice while its revision remains current. Failover/mirroring, enterprise login, door actuation and public-warning origination require this acknowledgment and are restricted to non-production labs. A successful simulated protocol check does not qualify a real provider, physical endpoint or multi-host installation. Offline operation still needs a working local PBX/network/power; cloud channels and fresh weather data remain unavailable during an internet outage.

## Install or update

Install or upgrade to `0.1.5-beta` using the version-pinned installer below.

Run as `root` on the PBX:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.5-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.5-beta/slsmassnotifyserver-0.1.5-beta.tgz' \
./sls-install.sh
```

Existing settings and credentials are preserved and their storage is migrated to AES-256-GCM. Fresh installations open a setup wizard in FreePBX. Check the displayed system timezone, complete setup under **Mass Notify**, and use FreePBX’s **Apply Config** button when it appears. Desktop capacity starts at 25; phone capacity starts at 25 or the rounded detected inventory described below. The wizard checks combined resource requirements before accepting a capacity increase. Its optional **Set hostname or forwarded ports** control starts unchecked, preserves existing addresses when unused, and accepts the external desktop, Control API and phone-image ports separately. The defaults remain HTTPS 443 and HTTP 80; router mappings are installation-specific.

An old Asterisk-owned installer log is handled automatically; there is no need to uninstall or disable Linux file protections. If installation still stops, keep `/tmp/slsmassnotifyserver-install.log` and see [installation troubleshooting](INSTALL.md#installer-log-and-runtime-errors).

The installer adapts to supported FreePBX configurations: it installs missing prerequisites, repairs SLS-owned permissions and services, reconciles pinned speech dependencies, rebuilds a broken managed Piper environment, and checks the actual local Apache virtual-host ports. AMI supports configured IPv4 or IPv6 loopback endpoints. Repairs preserve the central `.config` and administrator-controlled listeners, trunks, firewall, and handset settings. Resource shortages and unsupported configurations stop with an explanation; see [automatic repair boundaries](INSTALL.md#automatic-repair-and-configuration-variants).

This is beta software, not a replacement for certified emergency-alert equipment. Test your phones, desktop clients, and external destinations before relying on them. The installer verifies supported PBX capabilities; it cannot enable a handset’s auto-answer or XML push policy.

Phone and SMS contacts share one editor under **Locations and Audiences > Recipients**, with independent Call/SMS choices and recorded SMS consent. **General Settings > SMS > Check saved BulkVS sender** checks sender enablement and campaign assignment without sending a message. BulkVS inbound STOP callbacks remain unsupported; Twilio and Telnyx messages include a STOP instruction and enforce verified inbound opt-outs.

## Combined PBX/SLS resources and SLS storage

Voice selectors use file metadata and verified configuration files so page loads do not repeatedly read the entire speech catalogue. Speech generation and installation verify the complete selected model and its configuration against pinned checksums.

Announcement audio caches are checked before reuse; corrupt regular caches are rebuilt, while linked or special files are rejected. System Recording capture is read-only, bounded and nonblocking, including for administrator-owned files. These checks run during audio preparation and do not scan recordings on ordinary dashboard loads.

The FreePBX dashboard uses a bundled Chart.js 2.9.4 library for the legacy Sysadmin disk-space widget, avoiding its external CDN request. This uses a module page hook; FreePBX core and commercial module files remain unchanged.

**General Settings > Phones and address** shows required CPU, RAM, additional SLS free space and temporary workspace as either phone or desktop capacity changes. A green check means sufficient resources; a red X identifies an insufficient requirement. It uses the same combined resource checker as saved admission; previewing does not save settings. Shared filesystems are checked against the combined storage and temporary budget.

The installer and capacity controls check the **combined PBX and SLS workload**. Defaults are **25 desktops and 25 phone contacts**, requiring **2 effective CPU cores and 4 GiB RAM**. A fresh installation counts configured internal PJSIP devices, including offline devices, and additional registered contacts per device. Trunks are excluded. Above 25, the initial phone capacity rounds up to the next 50: 79 or 89 contacts selects 100; 125 selects 150. The rounded capacity must meet resource requirements before configuration is created. Upgrades preserve saved limits. Allow 4 cores and 8 GiB for ordinary PBX traffic, more concurrent synthesis, or call recording. These are engineering admission budgets; they are not a guarantee for an arbitrary workload.

| Configured desktop limit | Required CPU cores / vCPUs | Required RAM | Recommended CPU / RAM |
| --- | --- | --- | --- |
| 1–50 (default 25) | 2 | 4 GiB | 4 cores / 8 GiB |
| 51–100 | 3 | 5 GiB | 4 cores / 8 GiB |
| 101–250 | 4 | 6 GiB | 6 cores / 10 GiB |
| 251–500 | 6 | 8 GiB | 8 cores / 12 GiB |
| 501–1,000 | 8 | 12 GiB | 12 cores / 16 GiB |

For phone capacity `P`, let `extra = max(P - 100, 0)`. Add `ceil(extra / 200)` effective CPU cores and `extra × 2 MiB` RAM rounded up to a 256 MiB block to the desktop row. Each registered contact counts separately. The default remains 25 contacts; the baseline budget allows up to 100.

| Phone contact capacity | Additional CPU cores | Additional RAM | Combined minimum with 50 desktops |
| --- | --- | --- | --- |
| 1–100 (default 25) | 0 | 0 | 2 cores / 4 GiB |
| 150 | 1 | 0.25 GiB | 3 cores / 4.25 GiB |
| 250 | 1 | 0.50 GiB | 3 cores / 4.50 GiB |
| 500 | 2 | 1 GiB | 4 cores / 5 GiB |
| 1,000 | 5 | 2 GiB | 7 cores / 6 GiB |

An isolated Asterisk 22.8.2 PCM/ConfBridge fan-out check on October 2 measured about 0.06, 0.13 and 0.24 CPU core for 25, 50 and 100 recipients, with 35–52 MiB total Asterisk RSS. These measurements support reducing the earlier full-core/full-GiB jump above 50 contacts. The revised allocation retains margin for SIP, encryption and the running PBX. That check did not exercise real handsets, carrier calls, SRTP, transcoding or a 1,000-device fleet. For 1,000 desktops plus 1,000 contacts, the combined minimum is 13 cores and 14 GiB.

Capacity increases are rejected when CPU, memory or free-space checks fail. New desktop enrollment is rejected at the saved desktop limit with an actionable error. A phone audio audience larger than the saved contact limit is rejected. An eligible audience waits for occupied shared capacity within a bounded deadline; a timeout is reported without submitting that audio. Neither limit silently truncates an announcement's recipient list.

SLS storage is separate from the PBX operating system, recordings, voicemail, and backups. A read-only measurement on 2026-09-20 found **346.6 MiB of fixed SLS files**, **12.2 MiB of mutable data/media**, and **2.0 MiB of SLS logs** on the existing Debian 12 / Python 3.11 / amd64 PBX: approximately **361 MiB installed**, not a multi-gigabyte fixed footprint. The fixed files include a 159.2 MiB pinned Piper virtual environment, 180.7 MiB for the three bundled voices and their metadata, and module/runtime/API/assets. Counts use allocated filesystem blocks; no configuration contents are included in the report.

The current catalog includes four US English voices plus Spanish (Spain), French, German and Brazilian Portuguese. Its sixteen pinned model/config files total approximately **482 MiB**. Voice selection synthesizes the supplied text; it does not translate English Weather.gov alerts. `en_US-lessac-medium` is also available for external calls. To change only external calls, choose it under **General Settings > External Voice Calls > External speech voice**. The empty selection follows the ordinary announcement voice. Each submitted announcement freezes both choices; changing either setting does not change queued speech. Internal and external preparation failures are reported independently. Installation preserves existing voice selections. The generated telephone WAV remains compatible 8-kHz mono PCM, and the external carrier still determines the available call bandwidth.

The installer requires **additional free workspace**, derived from those managed trees and the exact package/model catalog. Existing allocations are measured again on every check. Missing runtimes use the measured pinned reference; missing voices use the sixteen exact catalog file sizes. The ten compatible pinned wheels total **77,802,965 bytes (74.20 MiB)** according to their [PyPI release metadata](https://pypi.org/pypi/onnxruntime/1.30.0/json), including [NumPy](https://pypi.org/pypi/numpy/2.4.6/json) and [Piper](https://pypi.org/pypi/piper-tts/1.8.0/json). The updated pinned environment measured **207.69 MiB** on October 2 and passed isolated speech generation with all four US English catalog voices. NumPy 2.5 requires Python 3.12, so this Python 3.11 release retains compatible NumPy 2.4.6. Pip uses binary wheels without a persistent download cache. A platform without compatible wheels fails with an actionable error; the amd64 measurement is not a universal architecture guarantee.

A read-only check on October 3, 2026 requires approximately **0.88 GiB additional persistent SLS free space** and **0.72 GiB temporary workspace** on this PBX (about **1.60 GiB** when both share a filesystem). The fresh-host reference is **1.29 GiB persistent** plus **0.72 GiB temporary** (about **2.01 GiB** combined). The installer recalculates this from actual allocations and missing models; this is free space dedicated to SLS, separate from total PBX disk capacity.

| Workspace component | Calculation |
| --- | --- |
| Runtime replacement | The larger of the measured runtime or the pinned environment plus a permitted package payload |
| Voices | Total missing catalog bytes, or one largest model for atomic repair, whichever is larger |
| State and configuration backups | Phone and Weather journal replacement space, the larger of the actual schedule journal or its 10 MiB backup limit, a 3 MiB encrypted configuration write, up to 25 concurrent 2 MiB job writes, and remaining space for 20 retained 3 MiB config backups |
| Module, public assets, and API copies | Each actual destination filesystem receives a conservative package-payload allowance: the enforced 50 MiB extraction limit plus allocation overhead for up to 2,000 archive entries |
| Event log compaction | Two 64 MiB copies for the original backup and retained temporary stream; event logs are compacted sequentially |
| Temporary installation/recovery | The enforced 52 MiB download limit and metadata, extraction and failed-release recovery, the measured old module, protected configuration snapshot, pinned wheel downloads, unpacking and old-package recovery, and one largest voice download |

These totals include approximately **283 MiB for state writes, bounded backup growth and log compaction**. They are conservative workspaces, not the installed size or an arbitrary 3 GiB requirement. Actual results change with managed file sizes, missing voices, and filesystem allocation units. The helper reports every component and path. Budgets add when paths share a filesystem; separate or bind-mounted voice/data/runtime/API locations are checked by device identity. No partition size, whole-volume minimum, or storage quota is imposed.

**Provision retained history and simultaneous rendering separately.** General Settings provides a combined generated-media cache target, default **512 MiB** (64–4096 MiB), stored as `generated_media_cache_mib` in the central `.config`. Periodic maintenance removes age-expired files first, then the oldest unused speech/images/phone XML if the combined payload bytes exceed that target. It preserves queued/playback/configuration/desktop references, custom recordings and the first 15 minutes after file creation. The target limits retained unused cache; it is not a hard disk quota or a reservation. Protected usage above the target raises a current storage warning, cleared by a later healthy measurement. Bounded cleanup retries automatically; incomplete inventories or unreadable references preserve files.

There is no enforced global speech-synthesis concurrency limit, so passing the workspace check does not establish that an arbitrary notification workload has enough storage. Raw mono 16-bit PCM uses `seconds × voice sample rate × 2` bytes, plus its WAV header; PBX audio uses `seconds × 8,000 × 2`. For example, 600 seconds uses 18.3 MiB at 16 kHz, or 50.5 MiB at 44.1 kHz, before converted speech and sequence copies. Budget raw audio, converted speech, the final sequence, and its temporary replacement for every simultaneously rendering job. Include opening/closing sounds in sequence duration.

For operational sizing, measure retained-byte growth across representative busy days and peak generation between successful cleanup runs; add that growth over the configured retention window to the measured footprint and required workspace. Terminal jobs are eligible after 30 days, generated images after 3 days, and unreferenced generated audio after 15 minutes. Active jobs, pending deliveries, and desktop history can retain media longer. Event-log retention follows the configured days; oversized or unsafe records can defer cleanup. Its temporary recovery files use the private `event-log-recovery` directory under SLS data, while live event logs keep their existing paths. Unfinished recovery files block another cleanup until reviewed. Monitor free space and deferred cleanup instead of treating those ages as disk quotas. Ordinary PBX recordings, voicemail, backups, and OS package work need their own budget.

- **Platform and measurement:** FreePBX 17 / Debian 12 with supported Asterisk/PJSIP capabilities. Checks honor CPU affinity and container CPU/RAM allocations. A 5% allowance applies to reported usable RAM; CPU and free-space requirements have no allowance. Sustained CPU matters: oversubscribed virtual CPUs can delay calls and speech.
- **Admission:** installation checks both configured capacities and SLS headroom before dependency/module changes. Capacity changes use the same combined calculation. Errors identify the measured shortage and required amount. Existing clients are never deleted to satisfy a failed check.
- **Desktop transport:** the global limit remains 32 simultaneous SSE connections, with two per desktop username. The reviewed desktop app supports five-second JSON fallback after a stalled or throttled stream. Authenticated polling, ACKs, and stream connections share a 120-request-per-minute budget per username; failed-login protection is separate, so clients behind one public IP do not share the normal traffic budget. Limiter storage supports the configured fleet up to 1,000 clients, and throttled responses include `Retry-After`. These limits and the hardware policy are not a 1,000-client load certification; test the fleet, burst rate, and PBX workload together.
- **PBX workload:** existing calls, transcoding, conferencing, recording, codecs, and simultaneous speech jobs consume additional resources. A passing check establishes eligibility under this policy, not tested throughput. Test the intended PBX and notification workload together.

The official [FreePBX installation baseline](https://www.freepbx.org/downloads/) lists 2 GB RAM and 20 GB disk for FreePBX itself; those are platform requirements, not SLS storage consumption. Asterisk documents that [Page creates conference participants](https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/Dialplan_Applications/Page/), [Local channels add processing cost](https://docs.asterisk.org/Configuration/Channel-Drivers/Local-Channel/), and [mixing intervals and resampling affect conference CPU use](https://docs.asterisk.org/Configuration/Applications/Conferencing-Applications/ConfBridge/). These sources explain the costs; they do not certify the numeric SLS policy above.

**Locations and Audiences** provides a site → building → floor → room directory for phone extensions, stable desktop identities, and saved external voice, email, and SMS recipients. Assign a recipient to one location, include child locations when appropriate, and review the exact audience before creating a saved announcement group. Groups are snapshots: location edits do not expand existing groups, schedules, incidents, or queued jobs. Removed, renamed, disabled, or replaced desktops require a newly reviewed group. The editor supports up to 500 locations, preserves unavailable assignments for review, and stages changes through Apply Config. Reviewed geographic audiences can be selected on an offline map or by exact boundaries, with visible exclusions for missing or stale coordinates. The saved group keeps its coordinates and recipients fixed. See [location administration](docs/LOCATIONS.md). This is configured location data; it does not track mobile devices. Assign operator site/location permissions separately in **Operator Access**.

**Dial-in Paging · Labs** is initially disabled. Choose one unused internal extension and create up to **10 paging groups**. Each group has its own name, menu number, live-audio phone recipients, optional SIP NOTIFY text recipients, saved text message, authorized caller extensions, and PIN settings. Internal callers must use an authenticated PBX phone authorized for the selected group. New groups require a randomly generated four-digit PIN by default; choose four through eight digits, randomize it, or disable the PIN requirement for internal callers in a particular group. Generated PINs are shown only at creation or randomization; stored hashes cannot be revealed. **Save group** persists the group and page settings; **Apply Config** prepares speech and activates the menu. The page distinguishes active configuration from saved changes.

**External IVR paging · Labs** requires a separate global opt-in, per-group external access, an explicit caller-number allowlist and the group PIN. Unlisted, withheld, missing or screening-failed caller IDs are rejected before the menu or PIN prompt. Approved callers hear only their permitted groups; they cannot select another group's PIN prompt. Store up to 100 approved numbers per group with `+` and country code, such as `+15125550123`. The list contains callers' numbers, not the PBX's inbound DID. An empty list allows nobody. Choose **SLS external paging · group PIN required** as a destination only in the intended FreePBX IVRs, then Apply Config; existing IVRs are not edited automatically. See [paging access and testing](docs/PAGING.md) for number matching and limits. Caller ID is a filter, not cryptographic identity; external PINs remain mandatory.

Live paging supports standard PJSIP extension/device identities. Remapped FreePBX device/user identities are not supported when excluding the calling device would change the selected group’s exact audio audience; admission is rejected and logs `phone_live_audience_changed`. No phones are paged in that case.

The paging editor uses the same header and section styling as the other SLS pages, with help controls and separate recipient/caller selectors. Existing paging references to announcement groups remain compatible; explicitly converting one in the editor saves its current members as an independent paging group. All paging configuration stays in `mass-notifications.config`, with changes staged for **Apply Config**. Prompts use the configured announcement voice. The calling phone is excluded, and busy audio recipients are not interrupted. SIP text goes only to its selected phones when an authorized page starts and follows the General Settings display timeout. Failed or uncertain text delivery is reported while live audio continues; text submission does not prove the handset displayed it. Large groups can exceed capacity or Asterisk dial-string limits even when registered. Asterisk-wide AGI/DTMF debugging can expose entered digits and should remain disabled when PINs are used.

## Using Mass Notify

- **Dashboard:** send text, colored phone alerts, tones, or Piper speech to selected phones, groups, desktops, saved email recipients, and optional announcement webhooks. A compact footer shows progress, a green check with the sender after successful submission, and expandable channel results. Choose Urgent to move ready audio ahead of waiting normal pages; active playback and cooldown remain protected.
- **Weather Alerts:** monitor up to five U.S. Weather.gov zones. Each zone selects its own phones, desktops, email recipients, webhooks, and quiet hours. EAS emails include the spoken announcement, affected areas, full NWS description and protective instructions; custom templates retain these details. Notification Details shows bounded email/webhook transport evidence, including HTTP failures. Old records without evidence remain explicitly unknown. New observations continue while queued alerts are delivered chronologically; expiry, cancellation, and overlapping-zone deduplication are checked before submission.
- **Lightning Alerts:** configure up to five areas with their own location, radius, strike type, destinations, quiet hours, and all-clear rules. Adaptive polling follows current Weather.gov alerts and the forecast period active now. Query cadence, provider token allowance, and fresh-observation checks still apply.
- **Scheduling:** choose specific dates or repeat every 7 or 14 days at a PBX-local time. Recurrence is a finite series of up to five years; review the displayed last occurrence. It does not renew indefinitely.
- **Locations and Audiences (Labs):** start with the default site, organize buildings/floors/rooms, combine a person’s devices or shared destinations, and review saved groups or individual devices for announcements, scheduling, Weather and Lightning. Recipient directories are managed here. Saved audience groups are immutable snapshots. See [locations](docs/LOCATIONS.md).
- **Operator Portal (Labs):** disabled by default; enable it in **Operator Access**. The PBX link opens in a new tab. Create independent logins in the PBX **Operator Access** page. Operators use HTTPS `/mass-notify/`, a personal password and mandatory authenticator verification. Assign actions, channels, sites, locations, saved audiences or personal devices; workers recheck permission changes. The separate portal defaults to dark mode and follows local PBX branding. See [operator access](docs/OPERATORS.md).
- **Operator recovery:** register an email per login for up to two self-service recovery emails. Administrators can generate or revoke a single-use 24-hour reset link; saving a new password requires the enrolled authenticator. Failed passwords have durable per-IP limits of 6/5 minutes, 12/10 minutes and 20/24 hours. Recovery and sign-in events are audited, and announcement history identifies the verified operator. See [recovery and sign-in limits](docs/OPERATORS.md#password-recovery).
- **Triggers and Actions (Labs):** configure enrolled panic sources, designated emergency-call observation, signed sensors, trusted CAP feeds, BrightSign/PATLITE actions and approved `.sh`/`.js` files. New entries start disabled; see [trigger contracts and limits](docs/TRIGGERS.md).
- **General Settings:** use the Phones, Channels, Audio, Desktops, Security and Maintenance categories for clients, provider/sender settings, tones, voices, volume, webhooks, Control API access, and phone formats. Download redacted diagnostics for support. Saved Channel Checks are optional reusable phone/desktop tests, explained by the question-mark beside their heading.
- **Incidents and Drills:** save templates with operator-fillable fields, frozen recipients, rosters and observer checklists. Send separate immutable updates/all-clear, record human responses and roll call, schedule drills, configure up to five follow-ups with their own destinations, and export paginated reports. See the [incident API contract](docs/INCIDENT_API.md) for permissions, limits and desktop integration requirements. Software receipts remain separate from human responses.
- **Notification Logs and Help:** review notification type, date, sender, destination outcomes, connection status, and diagnostic checks.

**Weather.gov outages during adaptive lightning monitoring:** the default **Standby** policy stops paid queries after usable forecast data and storm grace expire. **Temporarily continue polling** permits one query every five minutes for a configurable 15–120 minutes (30 by default), subject to the shared quota budget. Each area keeps its own durable deadline and attempt count; failed queries count, quota-denied attempts do not. The allowance starts when the outage is detected after grace, even in standby, and changing settings or restarting cannot renew it. Usable current weather data ends the outage. Cached forecasts can bridge a failed refresh for up to thirty minutes; missing current coverage is not “clear.” Area coverage distinguishes stale data, temporary fallback, exhausted allowance and quota denial. An outage never generates an all-clear. Disabling Adaptive protection selects the existing continuous mode and bypasses adaptive quota conservation.

Fresh defaults are Lessac for announcements, Amy for Weather and Lightning, and 25% volume for all three. Announcements include the opening and closing paging tones; Weather uses its NWS opening tone, Lightning its own opening tone, and neither has a default closing tone. Tones can be set to None. Generated audio includes one second of leading silence and is retained for fifteen minutes after reserved playback ends.

The paging answer window defaults to five seconds and can be shortened to one through five seconds in General Settings. It is separate from the server's visual-message expiry setting, which defaults to no expiry. The current desktop app applies its own ten-minute announcement maximum, described below. Longer ringing is not a substitute for configuring phone auto-answer.

Saved channel checks support up to ten named profiles with specific phones/desktops and audio-only, visual-only, or combined delivery. They use announcement settings, respect cooldown, and do not send email/webhooks or query Xweather. Save and apply a profile before running it.

To find a Weather.gov zone, open the [NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone), choose your state, and find your area’s three-digit zone number. Combine the state abbreviation, `Z`, and that number: Texas zone 163 is `TXZ163`.

## Delivery and compatibility

A shared phone admission ledger is used for announcements, Weather, Lightning, and dial-in paging. It freezes registered PJSIP contacts before call-file submission, rejects an audience larger than the configured limit, and waits briefly for occupied capacity. Active calls keep their slots until a complete Asterisk channel inventory confirms they have ended. A missing collector or incomplete inventory blocks new phone audio. The `sls-mass-notify-phone-events` service collects call evidence; Dashboard delivery details offer **Refresh phone outcomes**. Answer, conference entry, and hangup are evidence of channel activity, not proof of complete playback or human receipt. Validate the intended handset mix and call load before relying on this feature.

External voice is experimental and disabled by default. Saved international numbers can be selected on the Dashboard, in groups, and in schedules. The default delegates to FreePBX outbound routes; a specific PJSIP trunk and outgoing caller-ID/DID override can be selected. A DID selection supplies caller ID; it does not create an inbound route, and carrier/forced-trunk caller-ID policy still applies. Sent work keeps its original recipient and routing snapshot. Edited or revoked destinations cannot redirect an already submitted alert.

**External voice supports a restricted routing profile.** SLS checks the loaded FreePBX dialplan before submission and again before dialing. Supported calls ignore SIP forwarding using a call-local Dial option; ordinary PBX calls and saved trunk settings are unchanged. Reviewed FreePBX 17 CRM, missed-call, allowlist and outbound-route email metadata callbacks are accepted only when their exact context bodies and script hashes match the shipped compatibility profiles. Changed callbacks, route PINs, recording hooks, unknown call-producing hooks and incompatible per-trunk Dial overrides are rejected with a specific reason. Vendor module updates may require a new reviewed profile.

Each external recipient has an independent call and playback start. SLS requires a matching trunk-answer event for that recipient before playing the opening tone and speech; a Local-channel answer or another recipient answering is insufficient. Missing or interrupted answer evidence withholds playback and never triggers automatic redial. A carrier or voicemail answer is not proof that a person heard the announcement. Carrier delivery still requires an authorized live test; a passing route check does not place a call or confirm carrier acceptance.

**Optional keypad acknowledgement** asks the answered recipient to press **1** after the complete announcement. Choose a 5–30-second wait (default 10) in General Settings. The fixed English prompt is “Please press one to acknowledge this announcement.” The signed runtime includes this 8 kHz mono PCM prompt, generated with the existing Lessac medium model; no live synthesis or external service is needed while the person is on the call. Asterisk playback completion and the keypad result are recorded against the exact admitted call and shown in delivery details. Wrong keys, timeout, interrupted calls and prompt failures remain distinct. Duplicate results are safe. These events do not identify the person or establish understanding, and do not replace desktop software receipts or incident responses. The option starts disabled and does not change internal audio.

**Daily external-call allowance** is also optional: `outbound_voice.daily_call_limit` accepts 0–10,000, with zero preserving unlimited SLS admission under the existing simultaneous-capacity controls. Each admitted external recipient reserves one call before any call file is submitted. Failed/uncertain attempts are not refunded. The durable counter resets at 00:00 UTC, survives process restarts and delivery-history compaction, and rejects clock rollback or invalid counter state without resetting itself. It counts SLS admissions from this upgrade onward, not prior calls or ordinary PBX traffic. Budget rejection preserves eligible internal audio and reports the refused external destinations. Carrier prices/minutes are separate. Native restore disables external voice for review because a replacement PBX may lack prior call usage; review routing, recipients and the allowance before re-enabling it.

### Audio reservation durability

`0.1.5-beta` coordinates recorded announcements, Weather, Lightning, dial-in paging, and generated-media cleanup through a permanent audio reservation lock. Reservation updates replace complete, synchronized JSON documents instead of rewriting the active journal in place. Existing recipient, waiting-page, and media leases are preserved; cleanup holds the shared lock until deletion finishes.

Install these writers and readers together with the bundled installer. It waits for existing workers and live pages to drain and bridges the old journal lock with the permanent lock during migration. Standalone older audio helpers must also finish before the upgrade can proceed. Empty or corrupt existing journals, unsafe paths, and a missing previously initialized journal block new audio or cleanup with an error; they are not automatically reset. Preserve the journal, lock, and installer log for review rather than deleting them or restoring older delivery state. Isolated durability and interoperability tests do not establish handset playback or power-loss behavior on every storage device.

### Announcement email

General announcement email is included in `0.1.5-beta`. Validation uses isolated fake mail programs and browser fixtures; no real external email delivery or inbox receipt has been verified.

Open **General Settings > Announcement Email**, explicitly enable the channel, and save up to **50 named recipients**. It is disabled by default. Configure the existing email sender name/domain/local part, then **Save and Apply Config**. The Dashboard's **Email** selector supports search and selected counts; saved recipients can also belong to announcement groups, schedules, incident templates and supervisor follow-ups. Email-only announcements can use **Audio: None**. Saving recipients does not send mail. All configuration, including recipient IDs, enabled flags, addresses and sender identity, stays in the protected central `.config`; receipts and submission history are separate operational data.

SLS submits one separate text-and-branded-HTML message per selected address to the PBX's existing local mail service. It adds no provider credentials or SMTP configuration. A working local mail service/relay and appropriate domain setup remain necessary; changing the sender fields does not configure them. **Accepted by PBX mail service** means only local submission was accepted, not inbox delivery or human reading.

Existing schedules resolve their saved recipient IDs when due. Once an announcement is submitted, its recipient IDs, exact email addresses and sender identity are frozen. Edits, disabled destinations or revoked API permissions cannot redirect that queued work. Email submissions retain their original **15-minute expiry** and a **90-second batch budget**; an explicit retry does not renew the original expiry. Confirmed failed, retryable destinations may be retried explicitly while eligible. Accepted destinations are not resent, and interrupted or uncertain submissions are never automatically replayed. Incident updates/all-clear are separate announcements; they must still match the incident's originally frozen identities.

Webhook destinations support native JSON, Slack incoming webhooks, and Teams Workflows adaptive-card payloads. Choose the matching payload format for the destination URL. Teams currently supports secret-URL workflows using the **Anyone** trigger; tenant/OAuth-authenticated triggers are not implemented. Payloads are bounded and reject oversize messages without silent truncation. Typed incident messages include a protected **Open incident in SLS** link using the configured HTTPS PBX address and port; see [collaboration integration behavior](docs/COLLABORATION.md). Labs SMS adapters for Twilio, Telnyx and BulkVS are implemented, disabled by default. BulkVS currently reports API acceptance only; authenticated delivery and STOP callbacks remain unsupported. Sending fields stay disabled until a provider is selected; see [SMS setup, limits and receipts](docs/SMS.md). Mobile push and visitor enrollment are not available. SIP-speaker profiles, Entra identity integration and SLS notification failover are available only as disabled-by-default Enterprise Labs features; see the qualification limits above.

The [desktop application](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_app) is developed separately. The [reviewed app source at `c9baa8e`](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_app/tree/c9baa8eaf11fa1bd524dd7fcaa07ae4484ab25aa) implements **live-only delivery**: every new SSE connection starts at the current server tail, and broadcasts missed while disconnected, asleep, or shut down are skipped. After a stalled stream or HTTP 429, it honors `Retry-After` and falls back to authenticated five-second JSON snapshots. Initial and recovery snapshots establish a baseline and are not replayed. Deploy a matching app version; the server cannot make a sleeping app receive immediately.

Desktop JSON snapshots return the newest eligible window, up to 100 events when requested, and report `eligible_count` and `window_truncated`. Clients that need forward pagination can send `last_event_id` and follow `has_more`; `cursor_gap` identifies a cursor missing from retained history. Snapshot truncation is different from another forward page. The reviewed app uses snapshots, so delivery of more than 100 relevant publications between polls is not guaranteed by its current fallback. IDs, timezone-aware publication timestamps, and absolute expiry remain stable across repeated reads. Polling responses carry an HTTP `Date` and prevent caching. Delivery filtering does not remove announcement job results or receipt history merely because a popup expires; normal retention still applies.

Help & Diagnostics also reports desktop versions, connection activity and an optional advisory minimum-version policy. Apps can send the [additive readiness headers](docs/DESKTOP_READINESS.md); versions that are not reported remain unknown. This policy does not block delivery.

**Incidents and Drills** supports reviewed links to maps, instructions and responder references, plus up to six reviewed language variants. The sender previews one version for the saved audience; started incidents retain their original wording, links and review notes. English, Spanish, French, German and Portuguese variants can use a matching installed speech voice. Incident jobs freeze internal/external voices and verify model/config checksums on each later submission. Regional variants use the available catalog accent; unsupported languages require no speech or tones only. Supplied text is never translated. See the [incident content and API contract](docs/INCIDENT_API.md#reviewed-resources-and-language-variants).

**General Settings > Deployment readiness** checks current runtime/queue health, independent maintenance and worker heartbeats, clock synchronization, the served HTTPS certificate and resource headroom. The downloadable report is also available to an unrestricted read-only Control API credential for monitoring from a separate host. See [readiness checks and monitor behavior](docs/DEPLOYMENT_READINESS.md). This does not send an alert or certify device/provider delivery.

**Incident archival (Labs)** moves old closed incidents and missed drills out of the working list while retaining immutable reports and permanent replay identities. It is off by default. Configure its age/allocation in Incident Workflows, then Apply Config; archived reports remain readable and exportable. Full or damaged storage preserves active history. Native recovery blocks restored incident identities without activating old workflows. Larger archives need a separate protected export/migration because native operational evidence has explicit size bounds. See [incident retention and recovery](docs/INCIDENT_API.md).

The release gate now exercises real Apache/mod_php HTTPS polling, recipient isolation, durable receipts, SSE heartbeats and disconnect cleanup in a disposable network namespace. An additional 1,000-client synthetic run completed 3,000 shared-IP polls and 1,002 receipt requests. This establishes HTTP behavior in isolation, not qualification of 1,000 deployed desktops during production PBX call load. A standalone verified-HTTPS monitoring client and sample separate-host units are included in the [monitor documentation](docs/DEPLOYMENT_READINESS.md).

The Labs **Device acceptance** card records administrator observations of tests already performed against saved devices/routes, including model, firmware/app version, checked behavior and result. It sends nothing. The latest 200 records remain in `.config` and appear in the readiness export; older failures are superseded by newer observations, and settings/software changes request review. Keep this report confidential and export it for longer retention. Physical devices, provider routes and the public forwarding path still need their own acceptance tests.

Desktop presence is advisory: a locked or damaged presence file cannot block polling, receipts, or stream heartbeats. Authentication budgets use bounded locks and durable atomic replacements; corrupt existing budgets return a retryable storage error instead of resetting login attempts. Event journals are scanned incrementally with a 64 MiB file limit, 256 KiB record limit, and bounded scan/lock time. Corrupt or oversized evidence is preserved and reported explicitly; a stream already in progress sends a reconnect event before closing. These safeguards prevent a damaged file from silently clearing history or holding a request indefinitely.

General announcement expiry uses `display_timeout_seconds` and the original `display_expires_at`. The reviewed app additionally expires announcements **ten minutes after publication**, or at an earlier server deadline, including announcements whose server timeout is zero. This is a desktop presentation policy; it does not change the configured phone timeout or require a human response. Notification payload schema 1, SSE protocol 2, authentication, and endpoint paths remain compatible. Producers explicitly emit boolean `is_test`; test wording alone never changes that value. Incident updates/all-clear, human responses and panic triggers have explicit backend contracts; desktop support must use those contracts. Existing message wording does not activate a workflow or provide enrollment.

“Submitted” means the PBX accepted that channel’s work—not that a person heard it, a handset displayed it, or an email arrived. Failed channels do not suppress independent destinations. Retry is available only for confirmed failed announcement destinations; interrupted or uncertain submissions are not automatically replayed.

Scheduled occurrences now keep a durable link to their exact announcement job and a configurable 1–15-minute maximum start delay. Recipient-aware admission permits disjoint schedules to proceed independently; late unstarted destinations are skipped, and active calls continue. Weekday calendar patterns add holiday ranges, temporary start-time overrides and reviewed all-day CSV/iCalendar imports, with an exact-date preview before saving. See [scheduled delivery and recovery](docs/SCHEDULING.md) for limits, conflict warnings, deadline behavior and crash recovery.

The Dashboard can render the actual **480×272 phone image** and play **internal or external speech previews** before sending. Previews use private temporary images and return audio only to the authenticated administrator; they do not create announcements or contact recipients. Speech previews use the selected voice and the same duration check as delivery, and show the complete spoken text. Opening and closing tones are excluded from speech previews. Only one preview renders at a time; previewing does not hold the announcement-send lock. Changing the message invalidates an older preview. The SMS preview shows the complete text, encoding, segments and configured cost per recipient. General Settings also supports up to 50 literal pronunciation rules, entered as `phrase = spoken replacement`; matching is case-insensitive at whole phrase boundaries, longest first, without cascading replacements. Pronunciation affects synthesized speech only and is frozen with queued work. Expanded speech is still subject to the configured duration limit.

General announcements run in a background worker. The Dashboard distinguishes queueing, worker startup, running, completion, failure, and expiry. Startup or bootstrap failures retain a specific reason rather than leaving a permanent queued result. **General announcement worker** in diagnostics is separate from the scheduling check. This command checks its bootstrap and job storage without sending notifications:

```bash
php /usr/local/bin/sls_mass_notify/sls_mass_notify_announcement_worker.php --health-check
```

Desktop clients use authenticated live SSE at `/api/sipnotify/desktop/stream`, JSON polling at `/api/sipnotify/desktop`, and receipt ACKs at `/api/sipnotify/desktop/ack`. Every route enforces recipient authorization. Each new announcement's desktop delivery row stores its exact published event ID and destination identity. Results reconcile against that event's durable receipt, including when the ACK arrives before the job result is saved; another announcement's latest receipt cannot confirm it. The Dashboard labels a confirmed receipt **Received by desktop app**. This confirms software receipt, requires no Dismiss action, and does not mean a person read the message. Duplicate ACKs preserve the original receipt timestamp. Successful replies contain `ok: true` and the matching `event_id`; failed receipt persistence returns HTTP 503 with `Retry-After` so the app can retry. Old jobs without an exact event ID cannot be reliably matched after the fact.

The SSE source disables compression and PHP output buffers, pads and flushes each event/heartbeat batch, emits approximately 15-second heartbeats, and releases stream slots on exit. These changes still require verification through the site's actual HTTPS/proxy route; a successful authentication frame alone does not establish ongoing delivery or heartbeat health.

Colored desktop announcements use a separately rendered **1440×816** PNG with a unique generated URL and a **5 MiB maximum**. The desktop API supplies HTTPS on the configured PBX hostname and the validated request's external port, so forwarded ports retain the same origin as the app's connection. Generated images remain anonymously readable because the current app sends neither Basic credentials nor browser cookies for image downloads. Complete plain text remains available for Details and fallback rendering. Phone XML and **480×272** images retain their existing transport and media settings.

PJSIP UDP, TCP, and TLS contact syntax is preserved. Vendor payloads are selected per registration where Asterisk supports safe contact routing; otherwise a generic XML endpoint fallback is used. Unknown devices do not block installation, but may ignore generic XML. See [phone compatibility](PHONE_FORMATS.md) for provisioning requirements and limits.

SIP logs identify the route actually used (`endpoint` or `contact_uri`), AMI submission result, and any fallback. Partial registrations or unavailable requested phones are not reported as complete success. For Weather.gov poll faults, check the reported DNS, TLS, or HTTP category; a successful fresh poll clears the API fault without resetting alert history.

For phone images behind port forwarding, `sipnotify.media_base_url` can retain an external port for the configured PBX hostname. **General Settings > Phone Delivery** shows the effective image address. The port stays in that PBX's configuration; it does not change shared defaults, Apache's local listener, or SIP transport. Phones must be able to reach that address and validate its HTTPS certificate.

**Generated media access** in General Settings adds optional device-network restrictions and absolute download expiry for phone images/XML. Both are off by default, existing unauthenticated desktop image requests stay compatible, and delivery history is retained. See [media access and proxy configuration](docs/MEDIA_ACCESS.md).

To migrate SLS after a domain change, open **General Settings > Phone Delivery > Change advertised address**. Set the hostname and the ports clients actually use; desktop and Control API ports can differ. Review the generated URLs and use **Check local HTTPS**, then Submit and Apply Config. The check validates the local origin's certificate and unauthenticated desktop API response; it does not prove public NAT, proxy, or DNS routing. Verify the new connection from a desktop. DNS, certificates, firewall rules, desktop connection settings and the email sender domain are managed separately. SLS stores the advertised addresses in `mass-notifications.config` and does not automatically follow DNS or the operating-system hostname.

Phone display delivery uses at most four simultaneous senders, a twelve-second deadline per sender, and a ninety-second total display phase. Desktop publication and bounded webhook delivery precede that phase. Results distinguish successful submission, an uncertain started sender, and a sender that never started. Only the last category is safe to retry without risking a duplicate; no handset display is inferred from Asterisk acceptance.

Weather and Lightning delivery use two isolated workers. Different areas with disjoint frozen audiences can progress concurrently; shared recipients, webhook receivers, or area state remain ordered. Each admitted delivery has a durable claim and a deadline no later than 3,200 seconds after starting or one hour after initial queueing. The worker enforces that deadline if its coordinator exits. Already submitted Asterisk playback is not cut off; uncertain transmissions are not automatically replayed. Help diagnostics report queue/runtime ages and missed deadlines over the retained seven-day history. This changes delivery scheduling, not provider observation frequency or paid polling limits.

System Overview distinguishes current faults from delivery history. A failed announcement, scheduled occurrence or completed Weather delivery is a recent warning for **15 minutes**; its receipt/history remains available afterward. A newer successful job replaces the last-job warning, matching recovery evidence clears bootstrap/poll/voice faults, and disabled or removed features stop contributing old status warnings. Polling, collector, storage and installation failures still reflect their current checks. Unresolved external-delivery uncertainty and lost audit evidence remain visible for review; a successful local phone call cannot resolve them.

Configured system notification recipients also receive announcement-worker and storage/queue/audit faults through the existing notification email mechanism. Worker health older than ten minutes and storage health older than five minutes are reported as stale. Stable fault identities and existing retry/deduplication limits prevent changing counters from generating repeated new-fault mail. The collector itself still requires a functioning maintenance schedule and mail transport; it is not an independent outage monitor.


Discord uses the packaged `assets/webhook-builder.png` downloaded during development. Alert payloads refer to the PBX-hosted HTTPS copy with a content revision and the configured public Control API port; they do not fetch branding from the Southland Servers website. The card uses a small footer icon, with no large branding image or repeated author icon. The PBX image route must be reachable by Discord.

## SLS console

The signed installer adds `slsconsole` to `/usr/local/bin`. Run control commands with `sudo`:

| Command | Behavior |
| --- | --- |
| `sudo slsconsole status` | Show notification admission and the phone receipt collector state. Add `--json` for scripts. |
| `sudo slsconsole stop` | Pause new SLS notifications. Existing PBX calls and receipt collection continue. |
| `sudo slsconsole start` | Start the SLS collector if needed and resume notification admission. |
| `sudo slsconsole reboot` | Restart the SLS collector while admission is paused, then restore the previous running or stopped state. |

Use `slsconsole help` for usage. These commands do not reboot the host or restart Asterisk, Apache or the FreePBX firewall. Pause state is a strict `runtime_enabled` boolean in the encrypted central `.config`, synchronized with an existing staged configuration. Other settings and feature switches are preserved. Existing accepted notifications are not resent; deferred work keeps its original expiry. A failed collector restart leaves admission paused. Status and receipt APIs remain available while stopped.

Successful webhooks show service acceptance, without requiring a human receipt. A submitted webhook with no confirmed response remains visible as unconfirmed and is never automatically replayed; it does not raise a dashboard delivery fault. Explicit HTTP rejections and failures before transmission still need attention.

## Configuration, backup, and updates

All portable user settings and revocable desktop credentials remain in:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

The historical directory name is retained for upgrades. Active, pending and retained configuration files use authenticated **AES-256-GCM** encryption by default, with a 2 MiB decoded-settings limit and a 3 MiB encrypted-file limit. The root-controlled keyring is `/etc/sls-mass-notify/config-keys.json`; its directory is `root:asterisk` mode `0750` and the file is `root:asterisk` mode `0640`. Runtime readers can read keys but cannot replace them. Existing maintenance rotates the active key after **365 days**, retaining retired keys for older backups. Missing keys, damaged ciphertext and unsafe files fail closed. See [configuration encryption and recovery](docs/CONFIGURATION_SECURITY.md).

All user settings remain in the central configuration; staged changes become active through Apply Config. Execution journals, encryption keys and publisher authority are separate protected operational material. Encryption does not protect against a compromised PBX runtime account that can read its decryption key. The existing self-signing key remains unchanged; [release trust](docs/RELEASE_TRUST.md) documents reviewed rotation, overlap, expiry and offline recovery.

Use **General Settings > Config Backup > Download encrypted .config** for a portable configuration backup. Supply a separate passphrase of at least 12 characters and keep it outside the downloaded file. SLS does not save the passphrase or offer password recovery. The export uses authenticated XChaCha20-Poly1305 encryption with Argon2id key derivation; changed files or an incorrect passphrase are rejected before settings are staged. PHP Sodium is required. Legacy plain `.config` import/export remains available for compatibility; those files contain reusable credentials.

A portable configuration export contains settings, including incident templates and API permission definitions; it does not contain recordings or operational delivery history. General Settings reminds administrators to export a backup when none has been recorded or the latest export is over **90 days** old. The reminder sends no email and exports nothing automatically.

A module-based FreePBX backup includes encrypted configuration, its required recovery key, custom tones, schedule execution history, SMS continuity and a bounded operational recovery archive. Restore verifies the manifest and authenticated configuration, then encrypts settings with the destination PBX's active key. Historical weather, desktop, phone, announcement and incident evidence stays private and cannot replay old work. Restored automatic channels require review before rearming. See [backup, recovery and credential replacement](docs/RECOVERY.md) for limits and the separate-PBX acceptance requirement. Exports are audited before their bytes are released; an audit failure withholds the export. Install the module before restoring its module backup on a replacement PBX. Portable imports disable schedules because they lack execution history. **Native backups contain recovery keys: protect or encrypt the entire FreePBX archive.**

The [Control API guide](docs/CONTROL_API.md) documents authentication, capabilities, authorized audience discovery, previews, delivery jobs, incidents, human responses, fleet/readiness reports, triggers and error handling. Schedule administration remains in the FreePBX administrator UI. New endpoints are additive; desktop payload schema 1 and SSE protocol 2 remain compatible.

**Named API credentials** in General Settings let administrators assign separate read, send, Weather-test and configuration permissions to each integration. Secrets appear once at creation; SLS saves only their SHA-256 hashes. Restricted audiences can select phones, desktop identities, saved groups, external voice recipients, saved email and SMS recipient IDs, webhooks and Weather zones. Empty selections grant no access. Configuration permission requires an unrestricted audience. Creating or revoking a credential takes effect immediately and preserves unrelated staged settings. Queued work rechecks current permissions and cannot add recipients; revocation during speech generation or admission prevents subsequent call-file submission. Existing legacy-key clients remain compatible.

For announcement email, the existing Control API endpoint `/api/sls-mass-notify/` accepts saved IDs through `email_recipient_ids` (at most 50), using the existing authentication headers and `send` scope. Raw email addresses and caller-supplied sender identities are not accepted. For example, the following POST body sends an email-only announcement; replace the example ID with an enabled saved recipient ID:

```json
{
  "action": "send_announcement",
  "message": "The front office will close at 4 PM today.",
  "audio_mode": "none",
  "email_recipient_ids": ["email_aaaaaaaaaaaaaaaaaaaaaaaa"]
}
```

A restricted named credential grants direct email access through `audience.email_recipient_ids`; an authorized saved group's enabled members can also supply that permission. An empty email audience grants no direct email access. The worker rechecks current credential scope, group membership and enabled destinations before each submission. Example audience fragment:

```json
{
  "unrestricted": false,
  "email_recipient_ids": ["email_aaaaaaaaaaaaaaaaaaaaaaaa"]
}
```

Omitted email selectors preserve existing non-email API behavior. Calls containing email destinations return queued work; inspect its delivery receipts rather than treating queue acceptance as mail-service acceptance. See [incident email destinations](docs/INCIDENT_API.md#email-destinations) for template and escalation fields. These email additions are available in `0.1.5-beta`.

Configure **API reverse proxies** only when using a proxy you administer. List its exact IPv4/IPv6 CIDRs and configure it to overwrite `X-Forwarded-For` and `X-Forwarded-Proto`. Unlisted peers cannot supply trusted client addresses; malformed or missing forwarded information from a listed proxy is rejected. Forwarded hostnames are never trusted. Leave the list empty for direct connections. Proxy trust settings and credential management are restricted to the authenticated FreePBX administrator UI; Control API configuration mutations cannot change them.

Control API audit failures are separate from action results. If audit storage cannot confirm a record, the response includes `X-SLS-Audit-Status: unavailable` and a specific `X-SLS-Audit-Error`; an accepted announcement must not be resent just because its audit failed. A durable failure count is retained when storage permits and appears in diagnostics. Audit cleanup keeps the newest 10,000 eligible records within 30 days and preserves an original recovery copy before changing the log. If interrupted cleanup requires recovery, preserve `control-api-audit.jsonl` and its `.sls-retention-control-api-audit.jsonl.backup` / `.kept` files in the data directory for review. Oversized logs are left intact. External queue summaries report when their bounded scan is incomplete; partial counts must not be treated as a healthy empty queue.

Optional [audit forwarding](docs/AUDIT_FORWARDING.md) sends the same bounded record to the local system logger for collection by an existing remote logging agent. It defaults off. A verified successful append clears a recovered write warning while retaining historical missing-record counts; forwarding health distinguishes local acceptance from unverified remote receipt.


Manual and opt-in automatic updates verify a publisher-signed manifest covering the installer and TGZ. The updater resolves the release to a commit and installs the exact verified archive. New fixes require a new version; same-version replacements do not trigger updates. Older unsigned releases require their own tagged installer. See [installation and recovery](INSTALL.md) for offline installs, prerequisites, repair, and rollback.

Locally signed custom modules may display **Unknown** in FreePBX. Verification must still return trusted status `129`. Dashboard integration is checked and repaired after FreePBX updates; review health after any upgrade or restore.

Update and repair failures retain their command exit status and failure category. Do not treat a queued operation as finished. Existing Lightning strike-type settings are accepted across repair, import, upgrade, and restore; unknown or invalid settings still fail validation. Backups and active configuration changes coordinate with running general announcements rather than replacing their configuration mid-submission.

## Uninstall

Normal uninstall preserves the central configuration, backups, uploaded tones, and schedule execution history:

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.5-beta/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```

A complete purge is destructive and requires explicit confirmation. Configuration recovery keys are retained even after a purge so older encrypted backups remain recoverable; see [recovery-key retention](docs/RECOVERY.md) before removing them. See [INSTALL.md](INSTALL.md) before removing a live installation.

## Documentation and development

[Installation and recovery](INSTALL.md) · [Phone formats](PHONE_FORMATS.md) · [Changelog](CHANGELOG.md) · [Security](SECURITY.md)

Run `./tools/build_tgz.sh` from the repository root to run the release checks and produce `dist/slsmassnotifyserver-0.1.5-beta.tgz`. Signing requires the publisher’s private Ed25519 key outside the repository; `SLS_RELEASE_SIGNING_KEY` selects it. Publish the archive, `release-manifest.json`, and `release-manifest.sig` together. No configuration, credentials, voice models, generated media, logs, or private signing keys belong in the package.

On a development PBX, `bash tools/run_isolated_tests.sh` runs regression fixtures in private network, process, and mount namespaces with disposable PBX state. It requires root and Linux namespace support. It does not build, sign, install, or send alerts. Device delivery and destructive install/restore acceptance tests still require an explicitly authorized test environment.

[Southland Servers](https://southlandservers.xyz) · [Discord](https://southlandservers.xyz/discord) · [Report an issue](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues)

Dependency hashes, SBOM generation and offline development validation are documented in [Dependency inventory](docs/DEPENDENCIES.md). The new automated source/fixture workflow is described in [Validation](docs/VALIDATION.md).

Release channel, version pins, automatic windows and rollout delays are documented in [Update policy](docs/UPDATE_POLICY.md).

The Control API event log supports bounded newest-first pagination with `cursor`, `next_cursor`, `has_more` and `scan_limited`. A cursor freezes the file prefix while new events append. Invalid cursors return 400, changed/rotated snapshots return 409 (restart without the cursor), and unavailable or busy storage returns retryable 503. Each page reads at most 512 KiB and returns at most 100 records.
