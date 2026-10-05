# Installation Notes

## Requirements

- FreePBX 17
- Debian 12
- Asterisk with PJSIP endpoints
- FreePBX Framework, Dashboard, Backup & Restore, and System Recordings modules. The release installer installs a missing dependency or enables an installed disabled dependency; it does not silently upgrade an already installed core module.
- Active Apache and cron services, with Apache rewrite and authorization-header support
- Canonical `/usr/bin/php` CLI plus PHP OpenSSL, Sodium, mbstring, POSIX, cURL and PDO SQLite support for encrypted desktop credentials, scheduling, account lookup, bounded alert-text handling and durable SMS receipts
- Python 3 with `venv` and `pip`
- `curl`, `wget`, CA certificates, GnuPG, OpenSSL 3, `logrotate`, and `tar`
- Piper TTS runtime. The installer creates the root-owned `/usr/local/bin/sls_mass_notify/piper/venv`, exposes it at the compatibility path `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv`, installs the versioned `piper-requirements.txt` dependency set and pinned packaging tools, and downloads checksum-verified voices to the module data folder.
- SoX/soxi for audio conversion and normalization
- ImageMagick and DejaVu fonts for validated phone alert images
- cron plus `flock`, `timeout`, `readlink`, and `runuser`

The installer detects these capabilities and installs only missing Debian packages. When the PBX is already complete, it skips both package installation and the package-index refresh.

## Recommended Install

Run as `root` on the FreePBX server:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.5-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.5-beta/slsmassnotifyserver-0.1.5-beta.tgz' \
./sls-install.sh
```

The installer prints the PBX operating-system timezone before module activation. When it is run directly from an interactive terminal, you can keep the detected value or enter another timezone from `timedatectl list-timezones`. Scheduled announcements, quiet hours, logs, and alert timing all use this system timezone. Unattended and UI-triggered installs never prompt and keep the existing value. For a scripted timezone change, pass an exact IANA name explicitly:

```bash
SLS_MASS_NOTIFY_TIMEZONE='America/Chicago' \
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.5-beta/slsmassnotifyserver-0.1.5-beta.tgz' \
./sls-install.sh
```

The requested name must exist in both the systemd timezone catalogue and `/usr/share/zoneinfo`. If a later installation stage fails after changing it, the installer restores the original system timezone as part of rollback.

## Package verification and supported layouts

The updater resolves a release tag to its commit, verifies the publisher’s Ed25519 signature on `release-manifest.json`, checks both the installer and TGZ hashes, and hands the already verified local archive to the installer. Each release must publish `release-manifest.json` and `release-manifest.sig` alongside the TGZ. A fresh download still requires trusting the initial installer and its embedded public key. FreePBX’s locally generated module signatures serve a separate purpose.

For an offline/local package, obtain its SHA-256 through a trusted channel and use the matching version’s installer:

```bash
SLS_MASS_NOTIFY_TGZ='/tmp/slsmassnotifyserver-0.1.5-beta.tgz' \
SLS_MASS_NOTIFY_SHA256='<trusted 64-character SHA-256>' \
./sls-install.sh
```

Unsupported nonstandard FreePBX runtime layouts fail early with the required path/capability instead of being rewritten speculatively. A custom-built Asterisk that already provides a required capability is accepted; a missing unmanaged provider requires administrator repair. The installer does not replace custom Asterisk binaries, alter SIP peers, disable device security, or upgrade unrelated installed FreePBX modules.

## Install Hooks

The module install hook prepares the local PBX integration by applying managed configuration only:

- detects and installs only missing native prerequisites, then verifies the exact executable paths and PHP extensions used at runtime
- reports and validates the operating-system timezone before activation; an interactive root install can change it safely, while unattended installs require the explicit `SLS_MASS_NOTIFY_TIMEZONE` override and cannot pause for input
- copies runtime scripts to `/usr/local/bin/sls_mass_notify`
- copies API endpoints to `/var/www/html/api/sipnotify` and `/var/www/html/api/sls-mass-notify`
- copies web assets to `/var/www/html/sls_mass_notify`
- creates the central config at `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config` on a fresh install and preserves existing settings and credentials during updates; AES migration or key rotation can change the encrypted file's bytes without changing those settings
- has shell, Python, PHP, and API services read the central config directly
- initializes the email sender from the local Postfix/PBX identity on a fresh install. The local part defaults to `no-reply`, both parts can be edited, and opt-in system/error recipients remain separate from Weather and Lightning alert recipients
- installs the local AMI user through the FreePBX Manager module, which generates `/etc/asterisk/manager_additional.conf`, and uses FreePBX's validated loopback Manager host and port rather than assuming port 5038
- installs the direct audio and module-owned PJSIP auto-answer dialplan blocks in `/etc/asterisk/extensions_custom.conf`
- enables Apache directory access for the API/media paths
- installs the dashboard announcement widget compatibility files, rebuilds FreePBX Dashboard's persisted hook index, and verifies that the SIP NOTIFY announcement panel renders
- enforces `0640 asterisk:asterisk` on the protected central configuration after FreePBX ownership operations without changing its contents
- creates the Asterisk-owned one-minute Weather observer, a separate chronological delivery worker, and a bounded cross-zone destination-claim journal so overlapping zones cannot duplicate an alert to a shared target; adaptive Lightning polling uses current Weather.gov alerts plus the structured forecast period active at the current time, without spending Xweather tokens merely because thunder is forecast for a later period
- creates exactly one Asterisk-owned one-minute scheduled-announcement worker, verifies it as the `asterisk` account, and keeps its PBX-local execution ledger outside the module tree
- installs the ownership-safe **SLS Mass Notify - Paging Tone Opening**, **SLS Mass Notify - Paging Tone Closing**, **SLS Mass Notify - NWS Alert**, and **SLS Mass Notify - Lightning Alert** recordings and managed Asterisk audio. A conflicting user-owned recording stops installation instead of being overwritten
- verifies the real AMI contact-discovery and `PJSIPNotify` actions, matches registered numeric phones between the Asterisk CLI and AMI, checks spool access, sound links, WAV support, default audio formats, and the exact paging dialplan before reporting success
- verifies all Asterisk functions and applications used by the paging dialplan before module activation, including `PJSIP_HEADER`, `PJSIP_CONTACT`, `PJSIP_AOR`, `PJSIP_DIAL_CONTACTS`, `CUT`, `Page`, and ConfBridge. An installed but unloaded provider is loaded and rechecked; a Debian-owned missing provider can be repaired using the exact installed package without upgrading Asterisk, while an unavailable or unmanaged provider stops with its exact module path before any module replacement
- verifies every required Asterisk provider will load after restart. `autoload=no` systems must explicitly load each provider, and matching `noload` entries are rejected with the affected module name
- validates preserved central-config AMI credentials before activation and refuses a malformed legacy value without changing or printing it
- verifies the publisher-signed archive and installer before running packaged helpers, stages and syntax-checks the module, and retains root-private recovery material before activation. Fixed root helpers install protected files; FreePBX hooks and database integration run as `asterisk`. Failed activation restores validated static files and supported service state without rewinding delivery journals or invoking old module hooks as root
- compares every managed runtime, API, public asset, signer, and Dashboard file with its packaged source; stale managed files are removed without touching the central configuration or Piper models
- verifies all six Piper model/metadata hashes and performs a real synthesis with Amy, Lessac, and Ryan
- refuses to replace an existing `/usr/local/bin/piper` wrapper unless its contents prove it is already managed by SLS Mass Notify
- renders a phone image as the `asterisk` account in the real public media directory and retrieves the exact file through Apache
- completes an authenticated desktop live-SSE handshake without printing or storing the client password outside protected memory; Weather and Lightning publish the durable desktop record independently before the handset-only visual delay
- verifies an authenticated Control API status request when the Control API is enabled and loopback is allowed; disabled or deliberately loopback-blocked APIs remain valid configurations
- resolves every PJSIP contact for audio and pages them together through `Page()`/ConfBridge, so a softphone registration does not replace a desk phone registration. Mixed-family visual SIP NOTIFY uses contact-specific vendor payloads when URI routing is available and one safe generic endpoint payload otherwise; an unknown format alone does not block installation, and UDP/TCP/TLS SIP/SIPS URI syntax is retained
- accepts an authenticated Asterisk 22 `No Contacts found` AMI response as an authorized empty inventory on PBXs where no phones are currently registered, without accepting authentication or permission failures
- installs the signer from the authenticated publisher generation, checks protected expected module inventories before signing, and requires exact trusted status 129 for every touched module. Framework and Dashboard require an approved upstream/overlay baseline; existing local signatures alone cannot establish that baseline
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
7. Select phone extensions and/or enabled desktop clients for that primary zone. Each zone can independently select quiet hours, email recipients, Discord webhooks, and generic HTTPS webhooks; manual tests do not send external messages. After setup, use **Weather Alerts > Manage Zone Groups** to add as many as four more independently routed zones.
8. Configure quiet hours and critical bypass events.
9. Choose whether to enable the Control API. It is disabled by default.
10. After setup, configure desktop app clients in **General Settings**, review detected phone formats, and add manual extension overrides through the extension-and-phone-family popup only where needed. Desktop lists longer than approximately five rows use the sticky-header scroll region.
11. Select the announcement and weather TTS voices. Fresh regular announcements default to Lessac; Weather and Lightning alerts default to Amy.
12. Review the announcement, Weather Alert, and Lightning Alert volume controls; fresh installs default all three to 25%.
13. Set notification log retention.
14. Optionally configure Xweather under **Lightning Alerts**. Up to five named trigger areas can each select a Weather Alert group, strike type, location, radius, phones, desktops, email recipients, quiet hours, and all-clear behavior; enabled shared webhooks remain managed in General Settings. Adaptive protection is enabled by default and can open from a qualifying current Weather.gov alert or from thunder in the forecast period active at that time, then remains open for the configured grace period. A later forecast period does not start paid polling before its boundary. Multiple active areas can consume the shared account allowance faster. Lightning volume defaults to 25%, and coordinate locations are spoken as “this area.”
15. After setup, review **General Settings > Outbound Delivery**. Fresh installs send through local Postfix as `no-reply` at the detected Postfix/PBX domain; the sender local part and domain can be edited. Add system/error email recipients only if those operational notices are wanted. Weather and Lightning email recipients are selected inside their individual zones and trigger areas. Discord and generic HTTPS webhooks remain available from the same General Settings manager.
16. Complete setup, then use FreePBX’s standard top-right **Apply Config** control when it appears.

Notification Logs supports combined event-type and PBX-local calendar-date filtering. General Settings keeps repair, complete uninstall, and configuration replacement in separate Danger Zone cards so the scope of each confirmed maintenance action remains clear.

If Piper voices are missing after installation, use **General Settings > Repair installation**. Repair first admits the protected release generation and then checks the managed speech environment. Unsafe ownership, links or unreviewed code require resolving the specific diagnostic before retrying.

To find a Weather.gov forecast zone, open the [official NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone), choose the state, and find the three-digit zone number covering the location. Enter the two-letter state abbreviation, `Z`, and the three digits. For example, Texas zone `163` is `TXZ163`.

Scheduling supports one-time dates plus **Every 7 days** and **Every 14 days** recurrence. A repeating schedule starts at one future PBX-local date and time, then runs at that same local time. The module expands and validates a protected occurrence series covering up to five years; unsafe or ambiguous daylight-saving dates are rejected before the schedule is saved.

`fwconsole ma install` cannot safely ask interactive questions, so the mandatory setup wizard is implemented as this first-run FreePBX UI modal. Leave NWS disabled if the deployment only needs manual announcements, desktop notifications, SIP NOTIFY phone pushes, or TTS audio.

## Local channel checks and delivery state

General Settings can save up to ten named profiles with explicit phone/desktop targets and audio-only, visual-only, or combined checks. Save and apply the profile before running it. These checks use general-announcement audio settings and cooldown; they do not test the Weather.gov/Xweather fetch path and never send email or webhooks.

The paging answer timeout is one through five seconds, default five. It controls unanswered Page invitations, not visual-message expiry or the length of the prepared audio. Handsets still need correctly configured auto-answer.

Weather and Lightning observations are separate from queued delivery and external retries. Unsent work is bounded to one hour; interrupted work is marked uncertain and is not automatically replayed. NWS expiry/cancellation and current destination configuration are checked before submission. Consult the weather queue health check and channel logs when an observation exists without a delivered page.

Desktop protocol 2 fixes initial/reconnect event cursors and supports optional authenticated acknowledgments. The client must implement ACK to populate acknowledgment status. A connected stream is not proof of app display or human receipt.

## Runtime console

Installation, update and protected repair install and verify the publisher-authenticated `/usr/local/bin/slsconsole` entrypoint. Native restore checks its parity; rollback captures it, and uninstall removes it. Use `sudo slsconsole status`, `stop`, `start` or `reboot`. Stop pauses new notification admission, while existing calls and receipt tracking continue. Reboot restarts only the SLS phone collector and preserves a stopped state. Control commands preserve other active and staged configuration settings and do not restart PBX services. See README for the command table.

## Update Safety

Module code is installed under FreePBX modules. Runtime configuration is stored under:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin
```

Updates should not overwrite the central settings file. Use **General Settings > Danger Zone > Download .config** before major updates.

Portable `.config` exports include schedule definitions but not the PBX-local execution history, so importing one disables its schedules for review. Native FreePBX backups include the execution journal and use the replay-safe restore path described below.

The native FreePBX 17 backup adapter includes the protected config, schedule execution journal, custom module tones, SMS continuity when present, and a bounded operational evidence archive in module-based backup jobs. Historical queues, desktop inbox entries, storm state and incidents are archived for review, never reactivated. Weather/lightning automation and external voice remain disabled after restore until reviewed. See [recovery policy and acceptance checks](docs/RECOVERY.md). The installer enables FreePBX Backup, verifies adapter discovery, and enrolls Mass Notify in existing module-based jobs. A system with no administrator-defined jobs is healthy; create one in **Backup & Restore** when its schedule, storage, and retention policy have been chosen. Restores validate manifest records, SHA-256 hashes, size limits, config structure, credentials, and WAV data before an atomic replacement, then run post-restore integration repair. Due or completed occurrences are not replayed. FreePBX cannot download an unknown custom module from this project automatically, so install `slsmassnotifyserver` on a replacement PBX before restoring its archive and keep an independent `.config` backup.

Executable runtime, including Piper and the automatic updater, is root-owned. Mutable config, voice models, tones, journals, and generated audio remain in the Asterisk data folder. Generated TTS and combined announcement audio is removed automatically fifteen minutes after its reserved playback ends; queued media is leased until then. This prevents the Asterisk service account from replacing code later executed by a privileged maintenance/update job.

## FAQ

### Installer log and runtime errors

`AH00112: Warning: DocumentRoot [/invalid/folder/name] does not exist` is an Apache warning. If `apache2ctl configtest` exits successfully, it does not stop installation. The installer still requires valid Apache syntax and reachable PBX/API routes. Do not create that directory or change an unrelated virtual host merely to silence the warning. Keep the complete `/tmp/slsmassnotifyserver-install.log`; its stage and resource report identify the actual stop condition.

Free-space admission checks the filesystems containing the actual SLS directories, including dedicated or bind mounts. Shared filesystems are counted once. A small `/tmp` can use safe root-owned `/var/tmp` scratch space instead, and the selected location is printed in the log. There is no extra minimum on an unused parent directory. Insufficient target/scratch space still stops installation before dependency/module changes.

After an update-check failure, use **General Settings > Updates > Check for updates** to retry without installing, or **Retry update** to check and install a newer authenticated release. Network, TLS, GitHub rate-limit, policy and metadata failures have separate messages. These actions use saved/applied update policy and do not save other form changes.

The installer accepts an existing root- or Asterisk-owned regular log at `/tmp/slsmassnotifyserver-install.log`, secures the verified file, and keeps writing through its open descriptor even if FreePBX changes ownership. It does not disable Linux `protected_regular` or follow links. The uninstaller uses the same safeguards for its two diagnostic logs before removing anything.

On a minimal Debian 12 FreePBX host, missing Python is installed before opening the protected log. That initial package-manager output goes to the terminal. A broken existing interpreter is not replaced automatically. AMI, contact-inventory, and unauthenticated API probes use private temporary directories that are removed after the checks; failures retain their status and diagnostic summary in the installer log.

If a log is refused, inspect it with `stat` and check its parent directories. A symlink, hardlink, special file, or unexpected owner requires administrator review; do not work around this with `chmod 777`, disabled kernel protections, or a complete uninstall. Use a new absolute `SLS_MASS_NOTIFY_INSTALL_LOG` path inside a root-owned, non-writable directory if the old path must be preserved untouched.

For a runtime-integration failure, retain the installer log and the exact missing executable or mismatched file reported. For a Weather test where desktops succeed but the phone channel fails, check the matching `SIP_NOTIFY_TARGET` / `SIP_NOTIFY_RESULT` entries or sender error in `/var/log/sls_mass_notify.log`. The phone and desktop channels are independent; changing SIP transport or disabling TLS is not a general fix for that message.

### Audio reservation migration and recovery

`0.1.5-beta` changes the shared audio journal protocol. Install the complete package: the Python queue/admission helpers, PHP dial-in paging, media cleanup, idle inspector, and maintenance guard must agree on the permanent `audio-reservations.lock`. Updating only one helper can leave processes coordinating through different locks.

The installer first drains normal workers and all live-paging slots, then holds shared locks on the permanent sidecar and any existing legacy JSON inode while it checks idle state and activates the new runtime. Standalone older audio helpers must finish too; an active or uninspectable process is a reason to defer installation, not to force it through. The collector is restarted during activation before the maintenance guard is released. Existing reservations and phone-delivery history remain in place; static rollback must not restore older operational journals.

If an error reports corrupt or empty audio reservation storage, an invalid initialization marker, unsafe ownership/linkage, or missing initialized storage, retain `audio-reservations.json`, `audio-reservations.lock`, and the installer log for diagnosis. Do not delete either file to clear the error, copy an older journal over it, or relax permissions. An uncertain commit may already contain the new reservation even though the caller received an error; do not assume it is safe to replay the announcement. Resolve the reported storage problem and confirm playback/queued work is idle before retrying maintenance. The normal installer does not reset this state or change live notification configuration to bypass the checks.

### Automatic repair and configuration variants

The supported target is FreePBX 17 on Debian 12 with the documented conventional paths and Asterisk service account. The installer checks capabilities instead of requiring one exact Asterisk build. It can install missing supported dependencies, load installed Asterisk providers, repair safely identified Debian-owned providers at the installed Asterisk version, restore SLS-owned permissions, and regenerate and verify SLS integrations in FreePBX's required order. It does not replace an administrator's custom Asterisk build or change `autoload`/`noload` policy to bypass a failed check.

Local web checks discover bounded Apache virtual-host ports and connect only to loopback while preserving the configured hostname for Host/SNI. Public forwarded ports remain separate from local listeners. These checks do not modify Apache listeners, firewall/NAT rules, or the configured public address, and cannot prove a remote phone can reach that address.

The SLS AMI account permits IPv4 and IPv6 loopback only. A saved literal `127.0.0.1` or `::1` is preserved; a new configuration follows FreePBX when its manager host explicitly selects `::1`. `localhost` retains the existing IPv4 interpretation. An IPv6-only deployment must use literal `::1` in both relevant configurations. A conflicting saved endpoint is reported for review rather than silently rewritten, and remote AMI is unsupported. No administrator-controlled AMI listener is changed.

For speech, an executable file alone is insufficient: the managed environment's interpreter, environment prefix, pip, pinned packages, and Piper startup are checked. A broken interpreter or pip triggers recreation of only the protected SLS virtual environment; package-version drift is reconciled in place. Unsafe ownership, links, mounted subtrees, or unsupported wheel availability produce a specific error. Voice models and the central `.config` are preserved. This repair does not replace a broken system Python interpreter.

Repairs are followed by the same required postconditions as a new install, including API access under the web account, AMI capability checks after FreePBX reload, collector readiness, runtime parity, and local signatures. A failed upgrade attempts restoration and verifies the restored state. Incomplete restoration retains its recovery locations and fault notice. Hardware shortages, unrelated configuration faults, and unavailable external services cannot be fixed by skipping verification.

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
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.5-beta/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```

## Authenticated installation and recovery

The installer verifies the candidate SLS release and approves the previous release before changing protected runtime or maintenance. Existing reviewed Framework/Dashboard inventories remain authoritative. If they are missing, the installer downloads GPG-signed upstream packages at the exact installed versions, verifies the pinned FreePBX signing key, and compares deployed files with upstream bytes plus signed previous/candidate SLS widgets and the supported menu insertion. It accounts for Framework web-file mappings, installer-only omissions and the supported spinner symlink. It neither replaces stock files nor approves arbitrary live hashes.

Unknown differences stop preparation before mutation. The installer prints a retained root-private review directory containing `review-required.json`; independently review each reported change before supplying an approved inventory and its digest. Existing local branding and security changes must be preserved. See [the trust inventory procedure](docs/privileged-trust.md).

Run only the inventory preparation stage with the matching installer and verified release assets:

```bash
SLS_MASS_NOTIFY_INVENTORY_ONLY=1 bash ./sls-install.sh
```

For disconnected preparation, set `SLS_MASS_NOTIFY_UPSTREAM_PACKAGES` to a root-owned directory containing `dashboard-<installed-version>.tgz.gpg` and `framework-<installed-version>.tgz.gpg`. If no protected previous-release generation exists, supply all three root-protected paths: `SLS_MASS_NOTIFY_PREVIOUS_TGZ`, `SLS_MASS_NOTIFY_PREVIOUS_MANIFEST` and `SLS_MASS_NOTIFY_PREVIOUS_MANIFEST_SIGNATURE`. Otherwise the installer obtains the previous signed assets from the official release. These sources are authenticated before use; a previous local module signature does not establish trust.

The supported legacy Piper compatibility layout contains only `venv/bin/piper -> /usr/local/bin/piper`. The installer validates its exact contents and safe ownership/modes, retains recovery metadata, then recreates root-owned compatibility directories. A known authenticated wrapper missing its execute bit is repaired by dependency preparation. Unknown executables, additional files, unsafe links and writable environments require investigation; do not recursively change ownership to bypass validation.

The repaired 0.1.5-beta prerelease replaces the original assets under the same tag. Already installed 0.1.5-beta systems need a manual run of the current signed installer; automatic updates intentionally ignore equal versions.

Keep all recovery paths printed by a failed installer. Static recovery preserves the original snapshot and does not rewind notification or schedule execution history. Rollback restores the previous maintenance schedule only after its release, recovered web files and root runtime match the approval made before installation. If any recovery check fails, SLS root jobs remain disabled and the installer reports the failed postcondition and recovery paths. A restored file tree alone is not sufficient proof of a complete repair.

The installer verifies that generated image/XML requests pass through the configured [media access policy](docs/MEDIA_ACCESS.md), including legacy API aliases. It preserves existing restrictions, enables the required Apache modules idempotently, and distinguishes an intentional network denial from broken routing. Policy settings remain in the central `.config`; changing them does not restart the firewall or PBX.


### Candidate prerequisites and release trust

Dependency repair checks the selected CLI PHP version, installs its matching SQLite, cURL, XML/DOM and other required packages, then verifies the actual loaded extensions before module activation. Missing interpreter/package metadata, failed package operations and extensions that remain unloaded produce specific errors. JavaScript actions require `/usr/bin/node`; enabled CAP feeds require DOM and cURL. Resource shortages do not trigger destructive reconfiguration.

The current self-signing key is preserved. Release manifests now include signed expiry metadata. Optional reviewed publisher rotation/revocation and root offline recovery are documented in [Release Trust](docs/RELEASE_TRUST.md). Do not initialize a stricter publisher policy merely to troubleshoot an install: initialization intentionally disables legacy manifests without expiry metadata.

Fresh installation detects configured internal PJSIP phones and their registered contacts, excluding trunks. Phone capacity remains 25 for up to 25 contacts, then rounds up to the next 50: 79/89 selects 100, and 125 selects 150. Offline configured devices reserve one slot each. The rounded capacity must pass the combined resource check before any dependency/module changes, and again before configuration creation. The setup wizard displays this detected capacity. Upgrades preserve the saved limits; failed or incomplete inventories stop with an actionable error instead of guessing.

Setup offers an IANA timezone selector. Leaving the existing timezone selected preserves it; an explicit change is applied by the protected maintenance worker. The CLI installer keeps the existing timezone unless SLS_MASS_NOTIFY_TIMEZONE is supplied. See README for combined capacity and dedicated free-space budgets.
