# Changelog

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
