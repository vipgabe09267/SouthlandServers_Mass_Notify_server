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
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.0.9-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.9-beta/slsmassnotifyserver-0.0.9-beta.tgz' \
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
- initializes `mail_from_domain` from the detected PBX hostname on a fresh install and derives the fixed sender `no-reply@<domain>` at runtime. Legacy configs without the new key retain the domain from a valid `mail_from_addr`
- installs the local AMI user through the FreePBX Manager module, which generates `/etc/asterisk/manager_additional.conf`, and uses FreePBX's validated loopback Manager host and port rather than assuming port 5038
- installs the direct audio and module-owned PJSIP auto-answer dialplan blocks in `/etc/asterisk/extensions_custom.conf`
- enables Apache directory access for the API/media paths
- installs the dashboard announcement widget compatibility files, rebuilds FreePBX Dashboard's persisted hook index, and verifies that the SIP NOTIFY announcement panel renders
- enforces `0640 asterisk:asterisk` on the protected central configuration after FreePBX ownership operations without changing its contents
- creates the Asterisk-owned one-minute weather scheduler for U.S. weather.gov zone groups; free-tier adaptive Lightning polling uses one selected zone as its storm gate and queries Xweather every five minutes only while that gate or its grace period is active
- creates exactly one Asterisk-owned one-minute scheduled-announcement worker, verifies it as the `asterisk` account, and keeps its PBX-local execution ledger outside the module tree
- installs the ownership-safe **SLS Mass Notify - Paging Tone Opening**, **SLS Mass Notify - Paging Tone Closing**, **SLS Mass Notify - NWS Alert**, and **SLS Mass Notify - Lightning Alert** recordings and managed Asterisk audio. A conflicting user-owned recording stops installation instead of being overwritten
- verifies the real AMI contact-discovery and `PJSIPNotify` actions, matches registered numeric phones between the Asterisk CLI and AMI, checks spool access, sound links, WAV support, default audio formats, and the exact paging dialplan before reporting success
- verifies all Asterisk functions and applications used by the paging dialplan before module activation, including `PJSIP_HEADER`, `PJSIP_CONTACT`, `PJSIP_AOR`, `PJSIP_DIAL_CONTACTS`, and `CUT`. An installed but unloaded provider is loaded and rechecked; a Debian-owned missing provider can be repaired using the exact installed package without upgrading Asterisk, while an unavailable or unmanaged provider stops with its exact module path before any module replacement
- verifies every required Asterisk provider will load after restart. `autoload=no` systems must explicitly load each provider, and matching `noload` entries are rejected with the affected module name
- validates preserved central-config AMI credentials before activation and refuses a malformed legacy value without changing or printing it
- stages and syntax-checks the complete module before activation, retains a recoverable copy of the previous module during upgrades, removes partial integration before rollback, restores the prior module and protected configuration after a failed install, and retries a repaired loopback AMI integration before returning an error
- compares every managed runtime, API, public asset, signer, and Dashboard file with its packaged source; stale managed files are removed without touching the central configuration or Piper models
- verifies all six Piper model/metadata hashes and performs a real synthesis with Amy, Lessac, and Ryan
- refuses to replace an existing `/usr/local/bin/piper` wrapper unless its contents prove it is already managed by SLS Mass Notify
- renders a phone image as the `asterisk` account in the real public media directory and retrieves the exact file through Apache
- completes an authenticated desktop live-SSE handshake without printing or storing the client password outside protected memory
- verifies an authenticated Control API status request when the Control API is enabled and loopback is allowed; disabled or deliberately loopback-blocked APIs remain valid configurations
- uses portable endpoint fan-out for same-format phone registrations. Mixed formats on one extension require a complete contact URI for every phone and a usable Asterisk default outbound endpoint; unsafe cross-vendor endpoint fallback is rejected
- accepts an authenticated Asterisk 22 `No Contacts found` AMI response as an authorized empty inventory on PBXs where no phones are currently registered, without accepting authentication or permission failures
- restores the packaged local signer as an exact root-owned executable, discovers the FreePBX web account, module root, and GPG home from that PBX, repairs ownership on the selected keyring before importing trust, and requires exact trusted status 129 for every touched module
- serializes install, update, repair, and uninstall work with the root maintenance lock. A child installer launched by the maintenance worker reuses the inherited lock instead of deadlocking, while direct CLI operations wait for an active maintenance transaction to finish
- performs one final signing pass after reload. A candidate `module.sig` is published only after FreePBX verifies it; a failed verification restores the previous signature

## First-Run Setup

After installing the module:

1. Open the FreePBX Dashboard or any page under **Mass Notifications**. New installs show the setup wizard as a modal overlay.
2. Read the beta/non-production warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0-or-later license notice.
4. Read and accept the EULA.
5. Enable Weather Alerts only if you want U.S. weather.gov alerts.
6. If NWS is enabled, configure up to five named NWS zone/county groups, for example `TXC491`.
7. Select recipient extensions independently for each NWS zone group.
8. Configure quiet hours and critical bypass events.
9. Choose whether to enable the Control API. It is disabled by default.
10. Configure desktop app clients, review detected phone formats, and add manual extension overrides through the extension-and-phone-family popup only where needed. Desktop lists longer than approximately five rows use the sticky-header scroll region.
11. Select the announcement and weather TTS voices. Fresh regular announcements default to Lessac; Weather and Lightning alerts default to Amy.
12. Review the announcement, Weather Alert, and Lightning Alert volume controls; fresh installs default all three to 25%.
13. Set notification log retention.
14. Optionally configure Xweather under **Lightning Alerts**, including its location, radius, recipients, independent quiet hours, tones, TTS volume, and all-clear behavior. Adaptive protection is enabled by default for the 15,000-token allowance and uses the selected Weather Alert zone as its storm gate with a 60-minute default grace period; switching it off continuously polls Xweather regardless of NWS conditions. Lightning volume defaults to 25%, and coordinate locations are spoken as “this area.”
15. Add shared Weather and Lightning email recipients, review **Email Sender Domain**, and optionally add a Discord webhook through the **Notification Destinations** popup in General Settings. Fresh installs use the detected PBX domain, and the resulting sender is always `no-reply@<domain>`. Alert email is sent as a branded Southland Servers HTML card with a plain-text fallback.
16. Complete setup, then use FreePBX’s standard top-right **Apply Config** control when it appears.

Notification Logs supports combined event-type and PBX-local calendar-date filtering. General Settings keeps repair, complete uninstall, and configuration replacement in separate Danger Zone cards so the scope of each confirmed maintenance action remains clear.

If Piper voices are missing after install, run:

```bash
/usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh
fwconsole reload
```

NWS zone codes can be found from weather.gov forecast and alert pages. County codes look like `TXC491`; forecast zones look like `TXZ163`.

`fwconsole ma install` cannot safely ask interactive questions, so the mandatory setup wizard is implemented as this first-run FreePBX UI modal. Leave NWS disabled if the deployment only needs manual announcements, desktop notifications, SIP NOTIFY phone pushes, or TTS audio.

## Update Safety

Module code is installed under FreePBX modules. Runtime configuration is stored under:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin
```

Updates should not overwrite the central settings file. Use **General Settings > Danger Zone > Download .config** before major updates.

Configuration exports include schedule definitions but not the PBX-local execution history. An imported or FreePBX-restored schedule is therefore disabled until an administrator reviews and explicitly enables it on the destination PBX.

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

Back up the central `.config` file. The UI also provides download/upload controls under **General Settings > Danger Zone**.

### Can I install without NWS weather alerts?

Yes. Leave NWS disabled and use dashboard announcements, desktop notifications, direct Asterisk/PJSIP SIP NOTIFY, and TTS audio.

### Does audio require a FreePBX paging group?

No. The module uses the private Asterisk context `sls-alert-audio`.

### How do desktop clients receive live events?

Use the authenticated `/api/sipnotify/desktop/stream` server-sent-event endpoint. It uses the same per-client Basic credentials and target filtering as the `/api/sipnotify/desktop` JSON fallback.

### How are credentials generated?

Fresh installation generates random Control API, desktop encryption, desktop client, and AMI credentials when missing. These are stored in the central `.config` and preserved during updates.

### What does Email Sender Domain change?

It changes only the sender identity used by Mass Notify alert messages. Enter a DNS hostname such as `example.com`; the module sends as `no-reply@example.com`. The local part is fixed, and the canonical `mail_from_domain` value is stored in the protected central config so it follows a config backup or transplant. It may also be set through the allowlisted Control API configuration operation.

This does not configure Postfix, an SMTP relay, DNS, SPF, DKIM, DMARC, or PTR/reverse DNS. Confirm that the PBX mail transport and the selected domain's DNS policy authorize the sender before relying on external delivery.

## Uninstall

The default uninstall preserves the central config, config backups, uploaded tones, and `schedule-executions.json`. Preserving the PBX-local execution ledger prevents completed schedules from replaying after reinstall. The uninstaller removes the module-owned FreePBX Manager record and verifies that Apache/Asterisk artifacts are not regenerated and that Dashboard and Framework remain trusted. A bundled System Recording is removed only while its row, metadata, and audio hash still prove module ownership. Before the module hook removes the normal signer copies, the current uninstaller saves a protected temporary copy for the stock-module cleanup transaction. When FreePBX repository access is unavailable, that copy signs and verifies the cleaned modules and is deleted before exit; older releases use the compatibility fallback. Set `SLS_MASS_NOTIFY_PURGE_CONFIG=1` only when those deployment files should also be removed.

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.0.9-beta/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```
