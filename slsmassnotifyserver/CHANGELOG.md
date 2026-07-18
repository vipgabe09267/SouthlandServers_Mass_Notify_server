# Changelog

## 0.0.7-beta

- Fixed fresh-install paging portability. Direct pages now stage call files in Asterisk's same-filesystem spool temporary directory before an atomic move into `outgoing`, use a module-owned per-contact PJSIP auto-answer context with vendor-aware Panasonic, Poly, Mitel, OpenStage, and Sangoma header values instead of FreePBX's generated paging macros, and synchronously verify the visual SIP NOTIFY after the required one-second page-first delay. Announcement delivery is serialized, the sender has a 90-second safety timeout, and partial audio delivery retains the cooldown to prevent an immediate duplicate page.
- Strengthened the release installer so a failed module integration refresh can no longer be hidden by copied runtime files. It now verifies the exact audio and auto-answer dialplan handlers, Asterisk spool access as the `asterisk` account, both managed sound links, all four 8 kHz mono 16-bit default tones, AMI `PJSIPShowContacts` authorization, and live endpoint discovery whenever contacts are registered before reporting success.
- Fixed 0.0.6 upgrades that could copy and sign the Dashboard announcement files without refreshing FreePBX Dashboard's persisted hook index, leaving the SIP NOTIFY announcement panel absent. Install, repair, and maintenance now rebuild and verify the stored hook; the release installer also compares both Dashboard files and renders the panel before reporting success. The installer and maintenance worker reassert `0640 asterisk:asterisk` on the protected central config after FreePBX ownership passes and reject a symlinked config path.
- Made the release installer tolerant of the brief Asterisk reload window after `fwconsole reload`: it now retries and explicitly loads `res_pjsip_notify.so` for up to 20 seconds before reporting a genuine SIP NOTIFY failure.
- Fixed the General Settings manual updater so its cron-launched root worker has the complete system path and can reliably find FreePBX tooling. The update button is now shown only when a strictly newer beta release is available, forged/current-version requests are rejected server-side, and queued updates expose authenticated queued/checking/installing/complete/failed progress. The page displays a spinner while work runs, a green completion checkmark, and then refreshes to the installed version; failures remain visible with an actionable message.
- Added FreePBX Framework 17.0.30 compatibility to the update-safe Mass Notify menu integration. Framework changed its final-menu comparator from `return 1;` to `return true;`; repair, reinstall, maintenance drift detection, and uninstall cleanup now recognize both forms without duplicating or stranding the managed block. This compatibility fix does not install or upgrade Framework or any other FreePBX module.
- Fixed complete uninstall when FreePBX cannot redownload Dashboard or Framework. The standalone and packaged uninstallers now retry against a fresh stable repository cache, restore stock modules before deleting recovery tools, and use a locally verified cleanup signature as an availability-safe fallback. The fallback removes all private signing material, retains only the public verification key, avoids the raw `module.sig status 4` failure, and tells the administrator how to replace the temporary local signature with the vendor copy when repository access returns. The same path can repair a PBX after an earlier interrupted uninstall.
- Reworked the first-run wizard so Weather and Lightning are explicit opt-ins that default to No. Each integration's fields and audio options remain hidden and disabled until selected, disabled integrations retain their saved values, Lightning no longer browser-requires a Weather trigger zone while turned off, and compact checkbox alignment keeps labels beside their controls. Lightning Alerts now carries a Labs badge while Xweather support remains in beta testing.
- Simplified the README header with a smaller linked product mark and direct project summary, removed the redundant single-line installer duplicate, and retained one readable expanded installation command.
- Fixed fresh-install verification timeouts on PBXs with no registered PJSIP contacts. Installation now checks the Asterisk PJSIP inventory separately and validates the loopback-only `slsmassnotify` credentials with a bounded authenticated AMI `Ping`, instead of making the asynchronous contact-list completion event a mandatory install gate.
- Extended authenticated desktop events with explicit presentation metadata. Weather records now include their priority-derived background, header, accent, and text colors; colored announcements retain their selected title/background and style; and Lightning phone delivery also publishes a branded `#92400e` colored event to all authorized desktop-stream clients.
- Added a PBX-local calendar date filter to Notification Logs. It composes with event-type and row-limit filters, validates dates server-side, retains the native calendar picker, and provides a clear-filter action.
- Reorganized General Settings Danger Zone into three responsive, evenly spaced action cards for repair, complete uninstall, and protected configuration replacement without changing their confirmations or safety behavior.
- Removed the duplicate adaptive-protection status notice from Xweather connection usage. The green/red shield remains beside the actual toggle, spacing around the protection controls was increased, and the quota warning now appears only when continuous polling is selected and unsustainable.
- Hardened the authenticated desktop SSE handshake for Apache by flushing the initial event ahead of an SSE-safe output bucket and allowing abandoned connections to terminate. A 60-second production stream delivered the authenticated event before the client timeout; desktop applications that continue calling the JSON fallback still poll until updated to use `/desktop/stream`.
- Changed the default adaptive Lightning storm-mode grace period from 30 to 60 minutes in the runtime, normalized configuration, Lightning page, and setup wizard.
- Confirmed and labeled the fresh TTS profile defaults: Lessac at 25% for regular announcements, Amy at 25% for Weather Alerts, and the Weather/Amy voice at 25% for Lightning Alerts. The active PBX profile was aligned to those defaults.
- Added a bounded desktop-client table in General Settings: approximately five rows remain visible, larger lists scroll with a sticky header, narrow screens retain horizontal scrolling, and newly added clients scroll into view.
- Made Public PBX Hostname an automatically detected, read-only value in General Settings and the setup wizard, and removed it from Control API configuration mutations. Successful loopback `get_config`/`get_status` health probes are no longer written to or displayed as administrator Control API usage; failures and meaningful actions remain auditable.
- Changed the fresh-install and normalized fallback volume for regular announcements, Weather Alerts, and Lightning Alerts to 25%, while preserving independent 1–200% controls for each profile.
- Replaced the Lightning polling-strategy dropdown with a default-on Adaptive protection toggle. Enabled state uses a green shield and Weather.gov gating; disabled state uses a red shield and continuously polls Xweather at the configured period regardless of NWS conditions. The setup wizard uses the same control and behavior.
- Added four independent dashboard audio modes: no audio, tones only, TTS only, and tones plus TTS. Opening and closing FreePBX System Recordings can be selected per announcement, and either global or per-send tone may be set to **None** without changing the Asterisk dialplan.
- Added tone-only announcements so administrators can page selected sounds while still sending the typed text as the visual phone and desktop message.
- Added up to five named NWS zone groups, each with its own NWS county/forecast zone and extension recipients. The one-minute scheduler launches isolated zone workers so deduplication, cooldown, locks, and status do not collide between zones.
- Added all-zone or selected-zone manual NWS testing, including server-side validation of selected zone IDs.
- Added a dedicated **Lightning Alerts** tab and setup-wizard section for Xweather `lightning/closest`. It queries cloud-to-ground strikes, alerts once when a storm enters the configured radius, suppresses repeat strikes while that storm remains active, requires two clear polls before resetting, and can optionally send an all-clear message.
- Added an independent Lightning quiet-hours toggle and schedule, a configurable 1–10 minute API query period with a 5-minute default and standard-access coverage warning, dedicated recipients, a live system test, and Lightning-specific opening/closing tone selectors.
- Added a free-tier adaptive Lightning strategy that requires one selected Weather Alert zone, holds Xweather in zero-token standby until that zone reports an active Weather.gov thunderstorm event, polls every five minutes during storm mode and its configurable grace period, and enforces a 15,000-token monthly governor. The UI distinguishes cost tokens from HTTP request count and warns that 1–4 minute continuous schedules are not free-tier sustainable.
- Reduced each scheduled Xweather query to the single nearest cloud-to-ground strike and only the fields required for clustering and distance. The production response cost fell from 50 to 10 tokens per query; continuous five-minute polling would still cost about 86,400 tokens per 30 days, so adaptive coverage can miss lightning outside an NWS event.
- Bundled the regular paging opening/closing tones, `Lightning_alert.mp3`, and `NWS_alert.wav`; the installer registers all four in FreePBX System Recordings and creates managed 8 kHz Asterisk versions. Fresh regular announcements use the paging opening and closing tones, Lightning uses `Lightning_alert.mp3` with no closing tone, and Weather Alerts use `NWS_alert.wav` with no closing tone; all three profiles default to 25% volume.
- Added authenticated live desktop delivery through `/api/sipnotify/desktop/stream` using server-sent events, per-client Basic credentials, the existing strict target authorization, `Last-Event-ID` resume support, keepalives, and bounded reconnects. The JSON desktop endpoint remains available as a fallback.
- Added always-on multipart branded HTML weather email cards with an inline embedded Southland Servers logo, Southland Servers Group and SLS Mass Notification System headers, structured alert details, and alert-aware urgency colors/icons for tornado, severe storm, flood, winter, fire, lightning, test, and general alerts. A plain-text alternative remains included. Standards-compliant encoded headers prevent Unicode alert subjects from requiring SMTPUTF8, and undisclosed-recipient headers no longer add the sender as a delivery recipient. Email and Discord delivery remain inactive until configured.
- Moved the shared Weather and Lightning email recipients and Discord webhook into a compact **Notification Destinations** manager in General Settings, with add/remove rows, validation, masked webhook display, and no optional branding toggle.
- Replaced the free-form Phone Format Overrides textarea with a General Settings manager that accepts a numeric extension and a supported phone-family dropdown.
- Clarified the override names as **Yealink - Color** and **Yealink - Text Only**, removed the unnecessary Unknown/Safe Fallback manual option, added Panasonic KX detection/payload support, and extended ALE User-Agent detection. Unknown registered endpoints continue to be flagged in diagnostics.
- Integrated saved settings with FreePBX’s native top-right **Apply Config** lifecycle. The module stages protected configuration, marks FreePBX for reload, and applies it through the standard config-file hook; plugin-specific Apply buttons and explanatory staging banners were removed.
- Masked the saved Xweather Client Secret while allowing an authenticated FreePBX administrator to reveal it with an eye control; diagnostic and API output remains redacted.
- Gave Lightning tests a dedicated 60-second anti-spam cooldown and explicit **TEST ONLY** wording across phone, speech, subject, and branded email content.
- Changed Yealink Lightning visuals to the same validated 480×272, 8-bit sRGB, non-interlaced ImageScreen path used by alert images, avoiding the T48G long-text XML layout failure while retaining safe text fallbacks for other phone families.
- Fixed silent Lightning audio pages by generating current, underscore-only sound identifiers that survive the `sls-alert-audio` dialplan safety filter unchanged. Root-triggered validation and repair paths now also transfer call-file ownership to Asterisk before placing files in the outgoing spool.
- Added an independent 1–200% Lightning TTS volume control that defaults to the configured Weather Alert volume. Lightning speech now says “this area” instead of reading latitude/longitude aloud, while named locations use the configured city; live alerts report the nearest strike distance from Xweather to one decimal mile instead of merely repeating the configured radius. The combined page continues to include one second of leading silence before its pre-tone and speech.
- Reworked Notification Logs into a compact operational view with health cards, event-type and severity badges, Lightning filtering, clearer delivery summaries, improved empty states, and a structured event-detail layout.
- Added consistent, restrained pictogram icons to the major Lightning, Weather, General Settings, setup, and log sections to improve scanning without restoring visual clutter.
- Replaced long Discord field dumps with compact branded embeds using the Southland Servers/SLS identity, a public SLS logo image and avatar, alert-specific urgency colors and emoji, concise fields, timestamp, and explicit test footer. Weather, manual tests, and Lightning now use the same formatter.
- Replaced the oversized shared product banner with a compact branded identity bar so module pages retain visible ownership without losing vertical workspace.
- Changed the fresh-install weather voice default to Amy while retaining Lessac as the independent regular-announcement default. Lightning uses the Weather voice.
- Added automatic cleanup of generated Piper speech and combined announcement WAV files 15 minutes after use through send paths and the root maintenance worker.
- Preserved the root-owned Piper executable environment while restoring `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv` as a compatibility link for deployment checks and integrations expecting the documented data-tree path.
- Kept direct audio paging on `Local/<extension>@sls-alert-audio` and continued producing a single combined sound path with one second of leading silence. Optional tones do not add or alter `extensions_custom.conf` entries.
- Extended Control API configuration validation/redaction for NWS zone groups and Xweather settings, and extended NWS tests with explicit zone scope.
- Extended dashboard health, Help, setup, diagnostics, installer, runtime permissions, cron, repair, and uninstall handling for the new weather scheduler and Xweather worker.
- Renamed the user-facing tabs to **Weather Alerts** and **Lightning Alerts**, documented that Weather Alerts is limited to U.S. weather.gov zones, replaced the five stacked weather groups with a single summary and modal editor, and reorganized the Dashboard announcement widget into a linear four-step layout.
- Expanded Dashboard health pass-through to report invalid or missing weather zones/recipients, Lightning credentials/location/recipients/tones/query period, pending configuration, protected-config permission/ownership problems, missing API/Apache integration, stale polling, and poll/delivery faults.
- Validated the production/test PBX upgrade with byte-for-byte central-config preservation, PHP/shell/Python syntax checks, FreePBX reload, Asterisk dialplan/contact inspection, all three Piper voices, live and spoofed Weather Alert dry-runs, fixture-based Lightning entry/repeat/all-clear/new-storm transitions, authenticated and unauthenticated desktop live-SSE handshakes, 15-minute audio retention, UI server-side rendering, live Xweather credential authentication, and a branded simulated Lightning email accepted by the configured SMTP relay. Hardware delivery remains model- and firmware-dependent and requires site testing.

## 0.0.6-beta

- Added a Labs colored-announcement designer to the Dashboard with title, background color, and live preview controls. Colored image announcements are explicitly identified as Yealink-only; other supported phone formats continue to receive their vendor-specific text fallback.
- Hardened generated Yealink alert images with explicit dimensions, 8-bit sRGB PNG output, stripped metadata, non-interlaced encoding, and post-render validation to reduce blank-screen and image-load failures on legacy models.
- Added FreePBX System Recordings as opening and closing tone choices for both general announcements and NWS alerts. Selected recordings are path-validated, size-limited, and converted into managed 8 kHz mono Asterisk audio.
- Removed duplicate tone-upload controls from the plugin so administrators use FreePBX System Recordings as the single audio-upload workflow.
- Reorganized General Settings and NWS Alerts with clearer section headings, compact scrollable lists, and grouped tone selectors that remain manageable on systems with many recordings or clients.
- Fixed Dashboard colored-announcement expansion so the Packery layout is recalculated on dynamic content and resize changes, preventing the designer and send controls from extending into the FreePBX footer.
- Added minute-by-minute update-drift detection that restores only the managed Dashboard widget and menu placement after Dashboard/Framework upgrades, then refreshes their local signatures.
- Made desktop Client IDs generated and read-only in the UI, and preserved existing IDs server-side during settings saves. Desktop passwords and Control API keys are masked by default with explicit visibility controls.
- Expanded Phone Format Override guidance with concise examples, the complete supported-vendor list, and a clear notice that unlisted brands are not officially supported.
- Added a manual **Update to Latest Release** action. Update checks now run even when automatic installation is disabled, while installation still requires either the automatic-update setting or an explicit administrator request.
- Added yellow update-available indicators to General Settings and the FreePBX Dashboard health status using the same root-owned update-status source.
- Added a confirmed **Completely Uninstall** action in Danger Zone. The web request uses a protected marker consumed by the root maintenance worker and invokes the same tested standalone uninstaller with full configuration purge.
- Packaged the standalone uninstaller with the module runtime so queued UI uninstall follows the same cleanup path as the documented CLI uninstall.
- Improved first-run completion behavior so a successful setup returns administrators to the FreePBX Dashboard instead of opening the NWS page.
- Updated NWS, Piper, and updater User-Agent/version markers for the `0.0.6-beta` release.

## 0.0.5-beta

- Added a separate Phone Image Transport setting. Hosted phone images default to HTTP for legacy Yealink compatibility while authenticated Control and desktop APIs remain HTTPS.
- Fixed Yealink T48G XML loading failures by removing an undocumented ImageScreen attribute rejected by stricter legacy firmware and using a UTF-8 XML declaration.
- Fixed uninstall cleanup so FreePBX-managed AMI users are removed from the Manager database instead of allowing `/etc/asterisk/slsmassnotify` to be regenerated on every reload.
- Removed nested module reinstalls from the FreePBX uninstall hook, cleaned Apache enabled/disabled state, avoided restoring stale Dashboard backups, and added post-uninstall database, file, and stock-module signature verification.
- Added a scoped PHP CLI compatibility path for FreePBX commands on restricted LXC systems where PCRE JIT memory allocation is denied, without changing the server-wide PHP configuration.
- Fixed Control API authentication behind Apache rewrites by preserving `Authorization` on both API routes and accepting `X-API-Key` as an alternate header.
- Added strict Control API JSON size/content-type checks, constant-time key comparison, optional IPv4/IPv6 allowlisting, optional per-IP rate limiting, bounded audit history, and secret-free config responses.
- Added CSRF validation to every FreePBX state-changing form/AJAX action and HTML-safe JSON encoding for dashboard group, desktop, and extension data.
- Fixed central-config saves that could overwrite unrelated desktop clients, announcement groups, credentials, phone overrides, or NWS settings. Applied and pending config writes are now locked, private, and atomically replaced.
- Removed generated shell/Python settings copies. PHP, shell, Python, desktop API, and Control API now read the central `mass-notifications.config` directly so credentials and settings cannot drift between files.
- Added a stored Public PBX Hostname for desktop/API/media URLs, later made automatically detected and read-only to prevent inconsistent public-link configuration.
- Fixed desktop event authorization so each client only receives explicitly targeted or all-desktop records; legacy untargeted records are denied and one client's poll no longer removes another client's events.
- Fixed NWS status writes that could fail while delivery continued, validated GeoJSON structure before reporting a successful poll, and added actionable feature/event counts to status diagnostics.
- Hardened NWS requests with bounded retries, IPv4 fallback, response-size limits, an explicit `status=actual` query, and the required project User-Agent. Failed TTS or visual delivery is no longer marked processed, allowing a later poll to retry without replaying audio already queued.
- Fixed alert-chain deduplication so time-only updates and referenced reissues do not repeatedly page, while dry runs never mark an alert processed or send email, Discord, audio, phone, or desktop notifications.
- Improved multi-contact extension handling by retaining every reachable PJSIP contact, detecting all vendor formats present, logging the decision, and supporting per-extension manual overrides including `yealink_text` fallback.
- Fixed Yealink image consistency and delay by rendering one canonical 8-bit, non-interlaced PNG/XML payload per send and reusing it for phone and desktop delivery. Generated phone media/XML names now include cryptographically random components.
- Corrected Cisco Multiplatform payload delivery to use the documented `XML-Service` event with a hosted `CiscoIPPhoneExecute`/`CiscoIPPhoneText` flow, while documenting the vendor's digest-authentication requirement.
- Added one second of leading silence to the single combined NWS/test/announcement WAV so auto-answer setup cannot clip the opening tone. The dialplan now filters sound-path characters before playback.
- Moved the Piper executable environment into the root-owned runtime tree while keeping models and generated audio in the Asterisk data tree. The installer pins packaging tools, installs `piper-tts==1.4.2`, and verifies every voice model against a pinned revision and SHA-256 hash.
- Fixed the Repair Installation button so it queues a real root-owned maintenance worker instead of reporting success when the FreePBX web user lacked permission to repair system files.
- Reworked automatic beta updates as a root-owned, disabled-by-default job restricted to the official repository, exact beta tags, GitHub-provided SHA-256 asset digests, and the installer from the same immutable tag.
- Hardened release installation with config hash preservation, safe TGZ path/type/size validation, runtime permission repair after `fwconsole chown`, API/dialplan/Piper smoke tests, and trusted local verification of the SLS, Dashboard, and Framework files modified by the integration.
- Reworked uninstall cleanup to remove runtime, cron, API, Apache, signing-key trust, dashboard/framework integration, and obsolete menu artifacts while preserving the central config, config backups, and uploaded tones unless an explicit purge is requested.
- Added a release build gate that checks PHP/shell/Python/XML syntax, duplicate documentation parity, required files, embedded credentials, generated artifacts, unsafe archive members, expanded size, module identity, and reproducible TGZ metadata before packaging.

## 0.0.4-beta

- Added Help diagnostics for runtime health, detected phone endpoint formats, desktop client last-seen state, and recent Control API use.
- Added optional Control API IP allowlist, per-IP rate limiting, and 30-day API audit logging.
- Added config import schema validation before staging uploaded `.config` files.
- Added a General Settings repair action that refreshes runtime files, permissions, API routes, cron, dialplan, dashboard widget files, and local signatures.
- Added explicit Asterisk NOTICE logging for SLS audio page start, dial, playback, and completion.
- Added unknown endpoint vendor flagging and read-only endpoint detection diagnostics.
- Added NWS preview output for representative TTS, phone XML, desktop JSON, and email subject.
- Fixed Mass Notify menu placement and submenu order: Help, Logging, General Settings, NWS Alerts.
- Hardened Mass Notify top-menu placement after UCP/User Panel and added collapsible Help diagnostics for long phone endpoint, desktop, and API tables.
- Moved Repair Installation into General Settings > Danger Zone with an explicit confirmation prompt.
- Improved in-place upgrades so existing installs can be refreshed without running uninstall first.
- Improved large dashboard target lists with two-column, scrollable phone and desktop target selectors.
- Removed Show More/Show Less from dashboard target selectors so large systems use the scrollable selector directly.
- Reworked Help diagnostics tables to use scrollable panels for phone endpoints, desktop clients, and Control API audit entries.
- Reworked Help diagnostics into stacked panels to avoid table overlap on different FreePBX themes and screen widths.
- Added managed SIP NOTIFY templates on install and vendor-aware event/content-type fallback for Yealink, Cisco/Fanvil, Poly, Snom, Grandstream, Aastra/Mitel, Sangoma, Avaya, VTech, ALE, and unknown endpoints.
- Made the dashboard announcement widget more compact for larger PBX installs so the Send Announcement button stays visible.
- Hardened SIP NOTIFY endpoint detection for Asterisk contact states such as Avail, and made phone-targeted sends fail clearly if no requested endpoints are registered.
- Added installer smoke checks for the managed SIP NOTIFY templates and Asterisk PJSIP notify module.
- Removed the Stable update channel option until stable releases exist.

## 0.0.3-beta

- Hardened manual TGZ installs so rerunning the installer repairs runtime files, API rewrite routing, Piper executable permissions, generated config, and local FreePBX signatures.
- Replaced the Piper symlink with a wrapper that repairs executable bits before launching Piper.
- Added a configurable Piper TTS maximum duration with a 30-second default and 600-second maximum.
- Added the installed package version to General Settings > Updates.
- Fixed uninstall cleanup so the custom menu-placement patch is removed and stock module signatures are refreshed after dashboard/widget cleanup.
- Updated the release installer to explicitly re-sign and verify the SLS module plus Dashboard after runtime repair, FreePBX chown, and reload steps.
- Hardened local signing trust import across FreePBX GPG homes used by CLI and web verification.
- Added a final post-sign reload/sign pass so FreePBX sees valid signatures after installer repair.
- Fixed installer/signing aborts caused by FreePBX returning nonzero status for already-enabled modules or pre-existing unsigned/tampered state.
- Added a release installer hash guard so stale local release TGZ files fail before installing old package code.
- Expanded uninstall cleanup to remove generated runtime, API, Apache, sound symlink, signer, and temp installer artifacts while preserving central config files.
- Hardened Piper detection so installs work when `piper-tts` is available through the venv Python module path instead of a generated `venv/bin/piper` console script.

## 0.0.2-beta

- Expanded the Control API with authenticated remote announcement delivery, optional Piper TTS audio, announcement group targeting, colored/image announcement options, NWS test triggering, redacted config reads, and allowlisted config updates.
- Moved the Control API settings into a warning panel above Danger Zone in General Settings.
- Added Control API Help documentation for TTS announcements, colored announcements, NWS tests, and config updates.
- Hardened Control API config reads and updates so secrets are redacted by default and `[redacted]` placeholders are not written back over live credentials.

## 0.0.1-beta

- Initial public beta staging.
- FreePBX module renamed to `slsmassnotifyserver`.
- Central config export/import support.
- Notification log retention setting with 90-day default and 365-day maximum.
- Desktop API token staged in central config.
- Installer staging for runtime scripts, API endpoints, sounds, and cron.
- Piper voice models are downloaded during install instead of being stored in git or bundled in the module package.
- Mandatory first-run Setup Wizard with beta warning, AGPL-3.0 notice, EULA acceptance, NWS setup, Control API setup, phone delivery, desktop clients, TTS voices, volumes, and retention.
- Runtime permission repair for generated images, SIP NOTIFY logs, and Mass Notifications log files.
- Dashboard widget install cleanup removes legacy NWS dashboard widget files.
- SIP NOTIFY sender now honors `--no-api` for alert/image pushes as well as announcements.
