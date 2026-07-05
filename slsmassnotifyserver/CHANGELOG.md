# Changelog

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
