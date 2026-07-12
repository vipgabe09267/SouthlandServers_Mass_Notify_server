# Changelog

## 0.0.6-beta

- Added a Labs colored-announcement designer to the Dashboard with title, background color, and live preview controls. Colored image announcements are explicitly identified as Yealink-only; other supported phone formats continue to receive their vendor-specific text fallback.
- Hardened generated Yealink alert images with explicit dimensions, 8-bit sRGB PNG output, stripped metadata, non-interlaced encoding, and post-render validation to reduce blank-screen and image-load failures on legacy models.
- Added FreePBX System Recordings as opening and closing tone choices for both general announcements and NWS alerts. Selected recordings are path-validated, size-limited, and converted into managed 8 kHz mono Asterisk audio.
- Removed duplicate tone-upload controls from the plugin so administrators use FreePBX System Recordings as the single audio-upload workflow.
- Reorganized General Settings and NWS Alerts with clearer section headings, compact scrollable lists, and grouped tone selectors that remain manageable on systems with many recordings or clients.
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
- Added a configurable Public PBX Hostname for desktop/API/media URLs, fixing systems that generated an unreachable short host such as `https://pbx/...`.
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
