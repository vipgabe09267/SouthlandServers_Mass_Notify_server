# Southland Servers Mass Notifications Server 0.0.9-beta

Version 0.0.9-beta expands Mass Notify beyond immediate delivery with guarded calendar scheduling, adds regular-announcement display expiry and a portable alert-email sender domain, and strengthens installation and paging behavior across varied FreePBX 17/Asterisk systems.

This remains a beta release. Phone XML push and auto-answer behavior depends on each device model, firmware, provisioning, transport, and security policy. Test the exact endpoints and workflows used at the deployment, and maintain independent alerting paths.

## Scheduling

- Adds **Mass Notify > Scheduling** for one-time delivery on one or more PBX-local dates and times.
- Supports individual phones, announcement groups, individual desktop clients, all phones, and all desktops.
- Retains the normal announcement choices: text-only, tones, TTS, tones plus TTS, Piper voice, volume, opening/closing recordings, and Labs color presentation.
- Rejects nonexistent spring-forward times and ambiguous fall-back times instead of silently shifting them.
- Uses the PBX operating-system timezone and reports a Dashboard warning when it conflicts with FreePBX's configured PHP timezone.
- Runs once per minute as the `asterisk` account, with exactly one canonical protected cron entry.
- Uses a 15-minute grace window for pre-delivery cooldown, busy, or temporarily offline-target retries.
- Retries audio schedules with no currently reachable phone target inside that same grace window instead of failing them immediately.
- Claims an occurrence before submitting delivery. If a worker stops after that point, the occurrence is marked uncertain and is not replayed automatically.
- Revalidates the enabled schedule and exact occurrence under the settings lock immediately before claiming it, so a stale worker snapshot cannot send a just-disabled, deleted, or edited item.
- Fails closed if the worker lock or execution ledger cannot be opened safely, preventing an empty/corrupt ledger from duplicating a previously submitted page.
- Validates recipients, groups, desktops, tones, voices, audio targets, future dates, worker freshness, and cron state through Dashboard health.
- Coordinates schedule saves with active and staged configuration so Apply Config and General Settings cannot silently overwrite concurrent scheduling changes.
- Applies Control API and setup changes in one revision-checked settings transaction, preventing a newly staged UI change from being deleted in a lock gap.
- Disables schedules received through config import or FreePBX restore. Their definitions remain available for review, but their PBX-local execution ledger is not portable.
- Preserves the local execution ledger during a normal uninstall so completed occurrences cannot replay after reinstall; an explicit purge removes it.

## Announcement timeout

General Settings now provides three regular-announcement display policies:

- **No expiry** — the default.
- **Duration of Alert** — the visual timeout follows the remaining generated audio-page duration; visual-only announcements remain until dismissed.
- **Specified timeout** — 1 to 86,400 seconds.

Yealink announcement XML receives the timeout value. Authenticated desktop events receive timeout and UTC expiry metadata, and expired records are excluded from new desktop delivery while retained journal history remains auditable. The live stream advances past an expired authorized record without emitting it and without skipping the next valid targeted event. Other phone families depend on their firmware behavior. Weather Alert validity is not changed by this setting.

## Alert email sender domain

- Adds **Email Sender Domain** to **General Settings > Notification Destinations**.
- Fresh installs use the detected PBX hostname. The sender local part is fixed, so `pbx.example.com` produces `no-reply@pbx.example.com`; entering `example.com` produces `no-reply@example.com`.
- Stores the canonical `mail_from_domain` in the protected central config so the setting follows config backup, restore, and approved deployment transplants.
- Preserves upgrade behavior by deriving the domain from a valid legacy `mail_from_addr` when the new setting is absent.
- Applies the same strict DNS-hostname validation to the FreePBX form, imported configuration, and allowlisted Control API updates.
- Does not configure Postfix, an SMTP relay, SPF, DKIM, DMARC, PTR/reverse DNS, or other DNS/mail infrastructure. The PBX administrator must authorize the selected sender domain separately.

## Weather and Lightning fixes

- Corrects Weather.gov chain handling so a genuinely new `Alert` keeps its own identifier even if a downstream feed supplies historical references.
- Only `Update` messages inherit the oldest referenced chain identifier. A first-seen update can still deliver, while later timestamp/time-window edits in that same chain remain deduplicated.
- Keeps Heat Advisory in both the poller and FreePBX supported-event choices.
- Separates manual Lightning test status from live Lightning health.
- Replaces random expired call-file names with extension-specific test failures, such as a page not being answered within the test window.
- Migrates narrowly matched legacy `sls_xweather_*.call: Expired` status out of global/live fault fields during upgrade.
- Manual Weather and Lightning tests skip email and Discord destinations, including failure/fault notifications. Test failures remain local to the FreePBX result, status data, and Notification Logs.
- Alert-worker `--help` and unknown arguments now stop before configuration locks, external API requests, audio generation, SIP NOTIFY, desktop publication, or any other delivery side effect.

## Cross-PBX paging and installer hardening

- Preserves the outgoing PJSIP contact as the first auto-answer User-Agent source and adds extension → FreePBX device → AOR → contact fallback for community builds where the channel field is empty.
- Passes the target extension explicitly into the module-owned pre-dial header handler.
- Validates `PJSIP_HEADER`, `PJSIP_CONTACT`, `PJSIP_AOR`, `PJSIP_DIAL_CONTACTS`, `CUT`, and every other function/application used by the installed paging contexts.
- Validates canonical `/usr/bin/php`, PHP OpenSSL/mbstring/POSIX, Python/venv/pip, SoX/soxi, ImageMagick, fonts, download/archive tools, cron/locking utilities, AMI actions, runtime paths, Asterisk spool paths, and installed module files.
- Installs only missing Debian prerequisites. A complete PBX skips the package-index refresh instead of performing an unnecessary system package operation.
- Confirms every effective `PJSIP/<endpoint>` dial-string component resolves to a real endpoint object; a fabricated generic fallback cannot pass installation.
- Validates preserved AMI credentials and FreePBX's actual local Manager host/port before activation. A malformed protected config, invalid port, or non-loopback Manager host stops safely without being printed, overwritten, or paired with a transient generated secret.
- Verifies required Asterisk providers are not blocked by `noload` and will remain available after restart when `autoload=no` is used.
- Registers unique **SLS Mass Notify** System Recording names and refuses to overwrite an unrelated recording or `/usr/local/bin/piper` wrapper. Uninstall removes bundled recordings only while their metadata and audio hashes still prove module ownership.
- Accepts a genuinely empty authenticated PBX, but continues to reject AMI authentication/authorization failures.
- Does not modify phone firmware, provisioning, PJSIP peer definitions, or unrelated FreePBX modules.

## Naming and compatibility

User-facing **Plugin** wording is now **Module**, including Dashboard status. Existing data, Asterisk sound, image asset, and compatibility paths containing `SLS_Mass_Notifications_Plugin` are intentionally unchanged so upgrades do not lose configuration or audio.

## Installation

Run as `root`:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.0.9-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.9-beta/slsmassnotifyserver-0.0.9-beta.tgz' \
./sls-install.sh
```

The installer preserves an existing central configuration byte-for-byte. Fresh installation creates new credentials and opens the mandatory setup wizard.

Release artifact (Debian 12 / FreePBX 17):

- File: `slsmassnotifyserver-0.0.9-beta.tgz`
- SHA-256: `4c81256bb92af4b3b06a4434fe5b78d514f8abd16d581bbb2c0be3a4b99b8e0b`

## Operational limits

- Scheduling is serialized with ordinary announcements and respects the configured cooldown. Closely spaced items can run late and are eligible only during the 15-minute grace window.
- A successful Asterisk/AMI submission is not proof that a handset displayed XML or honored auto-answer.
- Yealink colored announcements remain Labs; other vendors receive safe text formats.
- Xweather support remains Labs and depends on the configured plan, token allowance, Weather.gov adaptive gate, DNS/TLS, and external API availability.
- Custom locally signed modules can appear as Unknown in Module Admin; trusted verification is the exact FreePBX result `{"status":129,"details":[]}`.
