# Installation Notes

## Requirements

- FreePBX 17
- Debian 12
- Asterisk with PJSIP endpoints
- FreePBX Framework, Dashboard, and System Recordings modules. The release installer installs a missing dependency or enables an installed disabled dependency; it does not silently upgrade an already installed core module.
- Active Apache and cron services, with Apache rewrite and authorization-header support
- Canonical `/usr/bin/php` CLI plus PHP OpenSSL, mbstring, and POSIX support for encrypted desktop credentials, scheduling, account lookup, and bounded alert-text handling
- Python 3 with `venv` and `pip`
- `curl`, `wget`, CA certificates, GnuPG, and `tar`
- Piper TTS runtime. The installer creates the root-owned `/usr/local/bin/sls_mass_notify/piper/venv`, exposes it at the compatibility path `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv`, installs pinned packaging tools plus `piper-tts`, and downloads checksum-verified voices to the module data folder.
- SoX/soxi for audio conversion and normalization
- ImageMagick and DejaVu fonts for validated phone alert images
- cron plus `flock`, `timeout`, `readlink`, and `runuser`

The installer detects these capabilities and installs only missing Debian packages. When the PBX is already complete, it skips both package installation and the package-index refresh.

## Recommended Install

Run as `root` on the FreePBX server:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.0/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.0/slsmassnotifyserver-0.1.0.tgz' \
./sls-install.sh
```

## Install Hooks

The module install hook prepares the local PBX integration by applying managed configuration only:

- detects and installs only missing native prerequisites, then verifies the exact executable paths and PHP extensions used at runtime
- copies runtime scripts to `/usr/local/bin/sls_mass_notify`
- copies API endpoints to `/var/www/html/api/sipnotify` and `/var/www/html/api/sls-mass-notify`
- copies web assets to `/var/www/html/sls_mass_notify`
- creates the central config at `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config` on a fresh install and preserves it byte-for-byte during updates
- has shell, Python, PHP, and API services read the central config directly
- initializes the email sender from the local Postfix/PBX identity on a fresh install. The local part defaults to `no-reply`, both parts can be edited, and opt-in system/error recipients remain separate from Weather and Lightning alert recipients
- installs the local AMI user through the FreePBX Manager module, which generates `/etc/asterisk/manager_additional.conf`, and uses FreePBX's validated loopback Manager host and port rather than assuming port 5038
- installs the direct audio and module-owned PJSIP auto-answer dialplan blocks in `/etc/asterisk/extensions_custom.conf`
- enables Apache directory access for the API/media paths
- installs the dashboard announcement widget compatibility files, rebuilds FreePBX Dashboard's persisted hook index, and verifies that the SIP NOTIFY announcement panel renders
- enforces `0640 asterisk:asterisk` on the protected central configuration after FreePBX ownership operations without changing its contents
- creates the Asterisk-owned one-minute weather scheduler for U.S. weather.gov zone groups; free-tier adaptive Lightning polling uses one selected zone as its storm gate and queries Xweather every five minutes only while that gate or its grace period is active
- creates exactly one Asterisk-owned one-minute scheduled-announcement worker, verifies it as the `asterisk` account, and keeps its PBX-local execution ledger outside the module tree
- installs the ownership-safe **SLS Mass Notify - Paging Tone Opening**, **SLS Mass Notify - Paging Tone Closing**, **SLS Mass Notify - NWS Alert**, and **SLS Mass Notify - Lightning Alert** recordings and managed Asterisk audio. A conflicting user-owned recording stops installation instead of being overwritten
- verifies the real AMI contact-discovery and `PJSIPNotify` actions, matches registered numeric phones between the Asterisk CLI and AMI, checks spool access, sound links, WAV support, default audio formats, and the exact paging dialplan before reporting success
- verifies all Asterisk functions and applications used by the paging dialplan before module activation, including `PJSIP_HEADER`, `PJSIP_CONTACT`, `PJSIP_AOR`, `PJSIP_DIAL_CONTACTS`, `CUT`, `Page`, and ConfBridge. An installed but unloaded provider is loaded and rechecked; a Debian-owned missing provider can be repaired using the exact installed package without upgrading Asterisk, while an unavailable or unmanaged provider stops with its exact module path before any module replacement
- verifies every required Asterisk provider will load after restart. `autoload=no` systems must explicitly load each provider, and matching `noload` entries are rejected with the affected module name
- validates preserved central-config AMI credentials before activation and refuses a malformed legacy value without changing or printing it
- stages and syntax-checks the complete module before activation, retains a recoverable copy of the previous module during upgrades, removes partial integration before rollback, restores the prior module and protected configuration after a failed install, and retries a repaired loopback AMI integration before returning an error
- compares every managed runtime, API, public asset, signer, and Dashboard file with its packaged source; stale managed files are removed without touching the central configuration or Piper models
- verifies all six Piper model/metadata hashes and performs a real synthesis with Amy, Lessac, and Ryan
- refuses to replace an existing `/usr/local/bin/piper` wrapper unless its contents prove it is already managed by SLS Mass Notify
- renders a phone image as the `asterisk` account in the real public media directory and retrieves the exact file through Apache
- completes an authenticated desktop live-SSE handshake without printing or storing the client password outside protected memory
- verifies an authenticated Control API status request when the Control API is enabled and loopback is allowed; disabled or deliberately loopback-blocked APIs remain valid configurations
- resolves every PJSIP contact for audio and pages them together through `Page()`/ConfBridge, so a softphone registration does not replace a desk phone registration. Visual SIP NOTIFY remains per endpoint/contact. Installation hard-fails for a mixed-format extension unless every contact has a usable URI and Asterisk has a usable default outbound endpoint; unsafe cross-vendor payload fallback is never accepted
- accepts an authenticated Asterisk 22 `No Contacts found` AMI response as an authorized empty inventory on PBXs where no phones are currently registered, without accepting authentication or permission failures
- restores the packaged local signer as an exact root-owned executable, discovers the FreePBX web account, module root, and GPG home from that PBX, repairs ownership on the selected keyring before importing trust, and requires exact trusted status 129 for every touched module
- serializes install, update, repair, and uninstall work with the root maintenance lock. A child installer launched by the maintenance worker reuses the inherited lock instead of deadlocking, while direct CLI operations wait for an active maintenance transaction to finish
- performs one final signing pass after reload. A candidate `module.sig` is published only after FreePBX verifies it; a failed verification restores the previous signature
- records a failed install or repair in protected state and FreePBX notifications. Dashboard health shows a red fault with the failed stage and a possible next step until comprehensive integration, signature, runtime, and health verification completes successfully
- installs the native FreePBX backup/restore adapters, checks module-based backup-job enrollment, and stages protected post-restore repair. A replacement PBX must already have this custom module installed before FreePBX can restore its module data

## First-Run Setup

After installing the module:

1. Open the FreePBX Dashboard or any page under **Mass Notify**. New installs show the setup wizard as a modal overlay.
2. Read the beta/non-production warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0-or-later license notice.
4. Read and accept the EULA.
5. Enable Weather Alerts only if you want U.S. weather.gov alerts.
6. If Weather Alerts is enabled, configure the primary Weather.gov forecast zone, for example `TXZ163`.
7. Select phone extensions and/or enabled desktop clients for that primary zone. Optional email recipients receive live alerts from that zone only; manual tests do not send external messages. After setup, use **Weather Alerts > Manage Zone Groups** to add as many as four more independently routed zones.
8. Configure quiet hours and critical bypass events.
9. Choose whether to enable the Control API. It is disabled by default.
10. After setup, configure desktop app clients in **General Settings**, review detected phone formats, and add manual extension overrides through the extension-and-phone-family popup only where needed. Desktop lists longer than approximately five rows use the sticky-header scroll region.
11. Select the announcement and weather TTS voices. Fresh regular announcements default to Lessac; Weather and Lightning alerts default to Amy.
12. Review the announcement, Weather Alert, and Lightning Alert volume controls; fresh installs default all three to 25%.
13. Set notification log retention.
14. Optionally configure Xweather under **Lightning Alerts**. Up to five named trigger areas can each select a Weather Alert group, location, radius, phone extensions, desktop clients, live-alert email recipients, and all-clear behavior. An area's email list is used only for that area's live alerts. Credentials, cadence, quiet hours, tones, and TTS volume are shared. Adaptive protection is enabled by default and gates each area from its selected Weather Alert group with a 60-minute default grace period; switching it off continuously polls every enabled area. Multiple storm-active areas can consume the account allowance faster. Lightning volume defaults to 25%, and coordinate locations are spoken as “this area.”
15. After setup, review **General Settings > Outbound Delivery**. Fresh installs send through local Postfix as `no-reply` at the detected Postfix/PBX domain; the sender local part and domain can be edited. Add system/error email recipients only if those operational notices are wanted. Weather and Lightning email recipients are selected inside their individual zones and trigger areas. Discord and generic HTTPS webhooks remain available from the same General Settings manager.
16. Complete setup, then use FreePBX’s standard top-right **Apply Config** control when it appears.

Notification Logs supports combined event-type and PBX-local calendar-date filtering. General Settings keeps repair, complete uninstall, and configuration replacement in separate Danger Zone cards so the scope of each confirmed maintenance action remains clear.

If Piper voices are missing after install, run:

```bash
/usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh
fwconsole reload
```

To find a Weather.gov forecast zone, open the [official NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone), choose the state, and find the three-digit zone number covering the location. Enter the two-letter state abbreviation, `Z`, and the three digits. For example, Texas zone `163` is `TXZ163`.

Scheduling supports one-time dates plus **Every 7 days** and **Every 14 days** recurrence. A repeating schedule starts at one future PBX-local date and time, then runs at that same local time. The module expands and validates a protected occurrence series covering up to five years; unsafe or ambiguous daylight-saving dates are rejected before the schedule is saved.

`fwconsole ma install` cannot safely ask interactive questions, so the mandatory setup wizard is implemented as this first-run FreePBX UI modal. Leave NWS disabled if the deployment only needs manual announcements, desktop notifications, SIP NOTIFY phone pushes, or TTS audio.

## Update Safety

Module code is installed under FreePBX modules. Runtime configuration is stored under:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin
```

Updates should not overwrite the central settings file. Use **General Settings > Danger Zone > Download .config** before major updates.

Portable `.config` exports include schedule definitions but not the PBX-local execution history, so importing one disables its schedules for review. Native FreePBX backups include the execution journal and use the replay-safe restore path described below.

The native FreePBX 17 backup adapter includes the protected config, schedule execution journal, and custom module tones in module-based backup jobs. The installer enables FreePBX Backup, verifies adapter discovery, and enrolls Mass Notify in existing module-based jobs. A system with no administrator-defined jobs is healthy; create one in **Backup & Restore** when its schedule, storage, and retention policy have been chosen. Restores validate manifest records, SHA-256 hashes, size limits, config structure, credentials, and WAV data before an atomic replacement, then run post-restore integration repair. Due or completed occurrences are not replayed. FreePBX cannot download an unknown custom module from this project automatically, so install `slsmassnotifyserver` on a replacement PBX before restoring its archive and keep an independent `.config` backup.

Executable runtime, including Piper and the automatic updater, is root-owned. Mutable config, voice models, tones, journals, and generated audio remain in the Asterisk data folder. Generated TTS and combined announcement audio is removed automatically after 15 minutes. This prevents the Asterisk service account from replacing code later executed by a privileged maintenance/update job.

## FAQ

### Why is there no terminal wizard?

FreePBX module install hooks are expected to run non-interactively. The setup wizard is shown as a first-run modal when the Dashboard announcement widget or a Mass Notifications page is opened.

### What is the central config file?

The source of truth is:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

### What should be backed up?

Use a module-based FreePBX backup job and keep an independent copy of the central `.config`. The UI provides download/upload controls under **General Settings > Danger Zone**. A green ready state with zero jobs means the adapter is installed but no backup policy has been created; configure a job in **Backup & Restore** and confirm Dashboard health reports its enrollment before relying on it.

### Can I install without NWS weather alerts?

Yes. Leave NWS disabled and use dashboard announcements, desktop notifications, direct Asterisk/PJSIP SIP NOTIFY, and TTS audio.

### Does audio require a FreePBX paging group?

No. The module uses the private Asterisk context `sls-alert-audio`.

### How do desktop clients receive live events?

Use the authenticated `/api/sipnotify/desktop/stream` server-sent-event endpoint. It uses the same per-client Basic credentials and target filtering as the `/api/sipnotify/desktop` JSON fallback.

### How are credentials generated?

Fresh installation generates random Control API, desktop encryption, desktop client, and AMI credentials when missing. These are stored in the central `.config` and preserved during updates.

### What does Email Sender Domain change?

It changes only the sender identity used by Mass Notify alert messages. The local part and DNS domain are editable; for example, `alerts` plus `example.com` produces `alerts@example.com`. The canonical values are stored in protected central config so they follow a backup or transplant, and validated settings may also be staged through the allowlisted Control API operation.

This does not configure Postfix, an SMTP relay, DNS, SPF, DKIM, DMARC, or PTR/reverse DNS. Confirm that the PBX mail transport and the selected domain's DNS policy authorize the sender before relying on external delivery.

## Uninstall

The default uninstall preserves the central config, config backups, uploaded tones, and `schedule-executions.json`. Preserving the PBX-local execution ledger prevents completed schedules from replaying after reinstall. The uninstaller removes the module-owned FreePBX Manager record and verifies that Apache/Asterisk artifacts are not regenerated and that Dashboard and Framework remain trusted. A bundled System Recording is removed only while its row, metadata, and audio hash still prove module ownership. Before the module hook removes the normal signer copies, the current uninstaller saves a protected temporary copy for the stock-module cleanup transaction. When FreePBX repository access is unavailable, that copy signs and verifies the cleaned modules and is deleted before exit; older releases use the compatibility fallback. Set `SLS_MASS_NOTIFY_PURGE_CONFIG=1` only when those deployment files should also be removed.

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.0/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```
