# Southland Servers Mass Notifications Server v0.0.7-beta

Southland Servers Mass Notifications Server `0.0.7-beta` is a major beta update for FreePBX 17 on Debian 12. It expands the product beyond its existing SIP NOTIFY, dashboard announcement, desktop, Weather.gov, Piper TTS, and direct Asterisk paging features with multi-zone weather routing, optional Xweather lightning detection, live desktop event streaming, branded outbound notifications, improved vendor support, and a substantial administration-interface refresh.

This remains prerelease beta software. Validate phone behavior, paging audio, external integrations, and alert routing on the target deployment before relying on it for safety-critical notification workflows.

The installer now tolerates Asterisk's short post-reload module startup window, retrying and explicitly loading `res_pjsip_notify.so` before treating SIP NOTIFY as unavailable. It also repairs and verifies FreePBX Dashboard's persisted announcement hook, renders the SIP NOTIFY announcement panel as a release gate, and enforces protected central-config ownership and permissions without changing saved configuration values. A successful install additionally proves the real AMI contact-discovery action, Asterisk spool access, both managed sound links, all bundled paging tones, and the exact module-owned audio/auto-answer dialplan.

## Highlights

### Multi-zone Weather Alerts

- The former NWS pages are consolidated under **Weather Alerts** and clearly identify that the integration supports United States Weather.gov data.
- Administrators can configure up to five named Weather.gov county or forecast-zone groups.
- Each group has independent extension recipients, and manual tests can target all groups or selected groups.
- Alert-chain normalization and deduplication prevent timestamp-only updates and referenced reissues from repeatedly paging the same event.
- Weather speech defaults to the Amy Piper voice at 25% volume and uses the bundled `NWS_alert.wav` opening tone with no closing tone.

### Dedicated Lightning Alerts

- A separate **Lightning Alerts** tab adds optional Xweather cloud-to-ground lightning detection.
- Administrators configure the protected Xweather credentials, location, alert radius, recipients, independent quiet hours, tones, volume, query interval, and optional all-clear behavior.
- A storm produces one entry alert when lightning first enters the radius. Repeated strikes from the same active cluster are suppressed until two clear queries reset the state.
- Live alerts report the nearest strike distance to one decimal mile rather than repeating only the configured radius.
- Coordinate-based locations are spoken as “this area”; named locations use the configured city or location label.
- Lightning defaults to the Weather/Amy voice at 25% volume, `Lightning_alert.mp3` as the opening tone, no closing tone, and one second of leading silence.
- The Lightning test has a dedicated 60-second anti-spam cooldown and is labeled **TEST ONLY** across phone, speech, desktop, email, and Discord delivery.

### Adaptive Xweather quota protection

- Adaptive protection is enabled by default and uses one configured Weather Alert zone as the storm gate.
- Xweather remains in zero-token standby until a relevant Weather.gov thunderstorm event activates storm mode, then polls through a grace period that defaults to 60 minutes.
- A protected token governor is designed around the 15,000-token monthly allowance.
- The default query period is five minutes. One-to-four-minute choices display a free-tier sustainability warning; six-to-ten-minute choices can miss strikes when the subscription only provides the recent five-minute lightning window.
- Disabling adaptive protection changes the UI to a red shield and polls Xweather continuously at the configured interval regardless of Weather.gov conditions.
- The Dashboard reports standby, quota protection, stale data, authentication problems, API faults, and other Lightning misconfiguration states.

### Announcement audio improvements

- Dashboard announcements support four independent audio modes: no audio, tones only, TTS only, or tones plus TTS.
- Opening and closing tones can be selected per announcement from bundled sounds or FreePBX System Recordings; either tone can be set to **None** without altering the Asterisk dialplan.
- Regular announcements default to the bundled paging opening and closing tones, the Lessac voice, and 25% volume.
- Weather and Lightning retain independent voice, tone, volume, and quiet-hour settings.
- Generated Piper and combined paging WAV files are automatically removed 15 minutes after use.
- Direct paging remains on `Local/<extension>@sls-alert-audio`, dynamically resolves the live endpoint, applies per-contact PJSIP headers through the module-owned `sls-alert-autoanswer` context, and passes one validated combined sound identifier to Asterisk. Panasonic, Poly, Mitel, OpenStage, and Sangoma retain their vendor-specific auto-answer values.
- Call files are staged in Asterisk's same-filesystem spool temporary directory before an atomic move into `outgoing`, avoiding both cross-filesystem `/tmp` moves and exposure of an incomplete file to Asterisk's outgoing watcher. The visual SIP NOTIFY is sent and checked synchronously after the one-second page-first delay instead of relying on a detached PHP child process. Delivery is serialized, bounded to 90 seconds, and keeps its cooldown after partial audio delivery so a retry cannot immediately duplicate the page.

### Live desktop transport and stricter targeting

- The PBX now provides an authenticated server-sent-event endpoint at `/api/sipnotify/desktop/stream`.
- The stream uses each desktop client's Basic-auth username and password, applies the same strict per-client targeting as the JSON endpoint, supports `Last-Event-ID`, sends keepalives, and provides bounded reconnect events.
- The initial authenticated event is flushed through Apache promptly, and abandoned streams are allowed to terminate.
- Every live notification includes renderable presentation metadata. Weather events provide priority-derived background, header, accent, and text colors; colored announcements preserve the selected title/background and `colored` style; Lightning uses its branded `#92400e` background. The same values are available as flat compatibility fields and in the structured `presentation` object.
- Lightning entry, all-clear, and test visuals are now published to all authenticated desktop clients in addition to their configured phone recipients.
- The existing `/api/sipnotify/desktop` JSON route remains available as a compatibility fallback. Desktop applications must explicitly use `/stream` to receive live events; clients that continue requesting the JSON route remain polling clients.
- Desktop lists longer than approximately five rows now use a bounded sticky-header scrolling region in General Settings.

### Branded email and Discord delivery

- Weather and Lightning email is always sent as a multipart branded Southland Servers notification with a plain-text fallback.
- HTML cards include an embedded Southland Servers logo, Southland Servers Group and SLS Mass Notification System identity, structured alert details, urgency colors, and event-specific icons.
- Tornado, severe storm, flood, winter, fire, lightning, test, and general notices receive distinct visual treatments.
- Discord delivery uses a compact branded embed with the SLS identity, logo/avatar, alert-aware colors and emoji, concise fields, timestamp, and explicit test footer.
- Shared email recipients and the optional Discord webhook are managed from the **Notification Destinations** popup in General Settings.

### SIP NOTIFY and phone-format improvements

- Manual overrides now use an extension field and supported-phone-family dropdown.
- Yealink choices are clarified as **Yealink - Color** and **Yealink - Text Only**.
- Panasonic KX-series detection and payload support were added, and Alcatel-Lucent Enterprise detection was expanded.
- Yealink image announcements use validated 480×272, 8-bit sRGB, non-interlaced PNG images with stripped metadata.
- Non-Yealink phones receive their vendor-appropriate safe text payload for Labs colored announcements.
- Unknown registered endpoints remain visible in diagnostics instead of being silently assigned a manual fallback.

### FreePBX administration and lifecycle

- General Settings now shows **Update to Latest Release** only when a strictly newer verified beta exists. The root maintenance and updater scripts establish an explicit cron-safe system path, current-version requests are rejected server-side, and the authenticated page reports queued/checking/installing/complete/failed progress with a spinner, completion checkmark, visible failure state, and automatic post-success refresh.
- Update-safe menu repair now supports Framework 17.0.30, which changed the final-menu comparator from numeric `1` to boolean `true`. Repair, reinstall, maintenance drift detection, and uninstall cleanup recognize either Framework form and keep exactly one managed menu block. SLS does not install or upgrade Framework or any other FreePBX module.
- Complete uninstall now survives a FreePBX module-repository outage. It retries Dashboard and Framework with a fresh stable cache, falls back to a locally verified cleanup signature when necessary, deletes the temporary private key, retains only the public verification key required to keep FreePBX usable, and prints the later vendor-redownload command. Rerunning the patched standalone script also repairs the `module.sig status 4` state left by the earlier uninstaller.
- The setup wizard now treats Weather Alerts and Lightning Alerts as explicit opt-ins that default to No. Integration-specific settings stay hidden and disabled until selected, declined integrations keep their saved values, and disabled Lightning no longer requires a Weather trigger zone.
- Setup acknowledgement and recipient checkboxes use compact control-to-label spacing, and Lightning Alerts displays a Labs badge while Xweather support remains under beta testing.
- Fresh-install verification checks the Asterisk PJSIP inventory, validates the loopback-only `slsmassnotify` credentials with a bounded authenticated AMI `Ping`, and proves authorization for the same AMI `PJSIPShowContacts` action used by delivery. When contacts are registered it also parses the live endpoint inventory; an empty PBX does not wait for an asynchronous completion event that some Asterisk builds omit.
- A failed direct module integration refresh now stops installation. The release gate also validates the module-owned auto-answer dialplan, Asterisk spool writability as the service account, both Asterisk sound links, and the four required 8 kHz mono 16-bit default tones.
- Settings now participate in the standard FreePBX **Apply Config** lifecycle.
- Public PBX Hostname is automatically detected and displayed read-only.
- The General Settings, Weather Alerts, Lightning Alerts, Notification Logs, setup wizard, Dashboard widget, and shared branding header received extensive layout and accessibility cleanup.
- Notification Logs now support event-type, PBX-local calendar-date, and row-limit filters with a clear-filter action.
- Danger Zone separates Repair Installation, Complete Uninstall, and Replace Configuration into distinct responsive cards.
- Dashboard health now reports configuration faults, protected-config permission problems, missing routes, stale polling, external API faults, pending settings, delivery faults, and available package updates.
- Repair and update paths restore the Dashboard integration, Framework menu placement, runtime permissions, API routes, cron entries, dialplan, and local signatures after FreePBX rewrites.

## Fresh-install defaults

| Profile | Voice | Volume | Opening tone | Closing tone |
| --- | --- | ---: | --- | --- |
| Regular announcements | Lessac | 25% | Paging Tone Opening | Paging Tone Closing |
| Weather Alerts | Amy | 25% | `NWS_alert.wav` | None |
| Lightning Alerts | Weather/Amy | 25% | `Lightning_alert.mp3` | None |

Additional defaults include a 30-second maximum spoken duration, 90-day notification-log retention, five-minute Xweather queries, adaptive protection enabled, and a 60-minute storm-mode grace period.

## Installation and upgrade

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.7-beta/slsmassnotifyserver-0.0.7-beta.tgz' \
  ./sls-install.sh
```

The installer supports fresh installation and upgrades. It preserves `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`, installs or repairs runtime dependencies and Piper voices, restores API and Dashboard integration, reloads FreePBX/Asterisk, and locally signs the touched modules.

Release asset SHA-256:

```text
8ee098f8bd7428f437c4dc334dbd96480c3cead8d81b7feeec707ec33742d506
```

## Validation performed on the production/test PBX

- Installed-module, repository-module, and TGZ payloads compared byte-for-byte, excluding local signatures.
- Runtime scripts, public API routes, Dashboard integration files, and public static assets compared with their packaged sources.
- Central configuration hash verified unchanged across exact-TGZ reinstall.
- All PHP, Bash, and Python sources passed syntax or AST validation.
- Rendered module JavaScript passed Node syntax validation.
- Asterisk direct-audio and module-owned PJSIP auto-answer dialplans, registered contacts, spool access, sound links, and bundled audio formats were inspected.
- A controlled production announcement to a registered Yealink T48G received `200 OK` for both the auto-answer INVITE and Yealink XML NOTIFY; Asterisk then reported the queued call complete.
- Amy, Lessac, and Ryan Piper models and metadata were verified, along with the runtime executable and compatibility wrapper.
- FreePBX verification returned trusted status `129` for `slsmassnotifyserver`, `dashboard`, and `framework`.
- Notification-log date filtering was verified against real PBX-local event dates.
- Authenticated desktop SSE returned HTTP 200 and the live authenticated event before the client test window ended; invalid authentication returned 401.
- Weather polling and Dashboard fault summaries were fresh and healthy at the release gate.
- The archive was checked for unsafe paths, credentials, protected configuration, models, signatures, logs, caches, backups, and generated artifacts.

## Known limitations

- This remains beta software and requires site-specific testing.
- Phone behavior varies by vendor, model, firmware, provisioning, and security policy.
- Colored image announcements are officially exposed for Yealink phones; other vendors receive text fallbacks.
- A desktop application must be updated to use `/api/sipnotify/desktop/stream`; older clients using the JSON endpoint continue polling.
- Weather delivery depends on Weather.gov availability, valid U.S. zones, internet connectivity, DNS, and TLS.
- Lightning delivery depends on Xweather account status, quota, coverage, and the adaptive-mode tradeoff; adaptive protection can miss lightning when no qualifying Weather.gov event activates the gate.
- The Dashboard integration modifies the stock Dashboard module and must be restored and locally signed after Dashboard or Framework upgrades. The installer and maintenance worker automate this repair, but it should still be verified after major FreePBX updates.
- Locally signed custom modules can display **Unknown** in Module Admin even when `verifyModule()` returns trusted status `129`.

For full setup, operations, API, troubleshooting, security, phone-format, and upgrade details, see `README.md`, `INSTALL.md`, `SECURITY.md`, `PHONE_FORMATS.md`, the in-module Help page, and `CHANGELOG.md`.
