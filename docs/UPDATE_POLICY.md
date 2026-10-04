# Release installation policy

General Settings → Updates and Retention stores these preferences in `updates` inside the central protected `.config`. Automatic installation remains disabled by default. Save and Apply Config before requesting an installation.

| Field | Behavior |
| --- | --- |
| `channel` | `beta` accepts beta and stable releases; `stable` accepts only stable releases. Existing beta installations retain their channel. Selecting stable can intentionally wait until a stable release exists. |
| `pinned_version` | Blank selects the newest eligible release. An exact version such as `0.1.5-beta` holds that version. The updater never downgrades a newer installed version. A beta pin requires the beta channel. |
| `window_start`, `window_end` | Optional `HH:MM` start-inclusive/end-exclusive window in the PBX operating-system timezone. Set both or neither. Overnight windows work. The window must cover at least one hour because checks run hourly at minute 17. It controls installation start; an active installation can finish after the window ends. |
| `rollout_delay_hours` | Integer 0–168. Automatic installation waits this long after the release's verified-format, timezone-aware publication timestamp. Different delays across PBXs provide an administrator-managed staged rollout. Missing or future timestamps defer automatic installation. |

A manual installation bypasses the automatic window and delay. It still respects the applied channel and exact pin. Check-only requests never install anything. No matching release is a successful hold, not an installation failure. The release search considers the latest 100 published-feed entries; a pin outside that window stays on hold and reports why.

The repository is fixed to the official SLS server repository. Neither a channel nor a version pin bypasses publisher signature checks. The updater binds the installer to the release commit, verifies the signed manifest, installer and package, and passes the exact verified package bytes to installation. Bad metadata, signatures or protected configuration stop installation.

This is per-PBX policy. There is no central rollout coordinator, automatic canary assessment, or rollback triggered by another PBX. The installer retains its local recovery checks and configuration/history protection.

Validation covers the shipped release-feed parser with mocked responses, form/API/import validation, automatic deferral, manual override, malformed feeds, and installation failure propagation in isolated fixtures. No real update has been published or installed to validate this policy yet.

The candidate updater installs and verifies the protected `slsconsole` entrypoint with the runtime. Recovery captures its previous bytes or absence; no console command is run to alter admission during update. A configured `runtime_enabled: false` state remains false across an update and continues to block new notifications.

Enterprise Labs presents its danger notice automatically until an administrator accepts it after five seconds. Updates preserve a current revision-bound page acknowledgment; native recovery clears Labs acknowledgments and disables Labs features. No update accepts the warning or enables a feature for the administrator.
