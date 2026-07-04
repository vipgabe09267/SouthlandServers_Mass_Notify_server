# Changelog

## 0.0.3-beta

- Hardened manual TGZ installs so rerunning the installer repairs runtime files, API rewrite routing, Piper executable permissions, generated config, and local FreePBX signatures.
- Replaced the Piper symlink with a wrapper that repairs executable bits before launching Piper.
- Added a configurable Piper TTS maximum duration with a 30-second default and 600-second maximum.
- Added the installed package version to Other Settings > Updates.
- Fixed uninstall cleanup so the custom menu-placement patch is removed and stock module signatures are refreshed after dashboard/widget cleanup.
- Updated the release installer to explicitly re-sign and verify the SLS module plus Dashboard/Framework after runtime repair, FreePBX chown, and reload steps.
- Hardened local signing trust import across FreePBX GPG homes used by CLI and web verification.
- Added a final post-sign reload/sign pass so FreePBX sees valid signatures after installer repair.
- Fixed installer/signing aborts caused by FreePBX returning nonzero status for already-enabled modules or pre-existing unsigned/tampered state.
- Added a release installer hash guard so stale local 0.0.3 TGZ files fail before installing old package code.
- Limited local signing to the SLS module and Dashboard, which are the only FreePBX modules modified by this package.
- Expanded uninstall cleanup to remove generated runtime, API, Apache, sound symlink, signer, and temp installer artifacts while preserving central config files.
- Hardened Piper detection so installs work when `piper-tts` is available through the venv Python module path instead of a generated `venv/bin/piper` console script.

## 0.0.2-beta

- Expanded the Control API with authenticated remote announcement delivery, optional Piper TTS audio, announcement group targeting, colored/image announcement options, NWS test triggering, redacted config reads, and allowlisted config updates.
- Moved the Control API settings into a warning panel above Danger Zone in Other Settings.
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
- Mandatory first-run Setup Wizard with beta warning, AGPL-3.0 notice, EULA acceptance, NWS setup, Control API setup, SIP NOTIFY endpoint selection, TTS voices, volumes, and retention.
- Runtime permission repair for generated images, SIP NOTIFY logs, and Mass Notifications log files.
- Dashboard widget install cleanup removes legacy NWS dashboard widget files.
- SIP NOTIFY sender now honors `--no-api` for alert/image pushes as well as announcements.
