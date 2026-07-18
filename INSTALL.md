# Installation Notes

## Requirements

- FreePBX 17
- Asterisk with PJSIP endpoints
- Apache/PHP as provided by FreePBX
- Python 3
- Piper TTS runtime. The installer creates the root-owned `/usr/local/bin/sls_mass_notify/piper/venv`, exposes it at the compatibility path `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv`, installs pinned packaging tools plus `piper-tts`, and downloads checksum-verified voices to the plugin data folder.
- `sox` for audio conversion and normalization
- `curl` for NWS, Xweather, and API calls

## Recommended Install

Run as `root` on the FreePBX server:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.7-beta/slsmassnotifyserver-0.0.7-beta.tgz' \
./sls-install.sh
```

## Install Hooks

The module install hook prepares the local PBX integration by applying managed configuration only:

- copies runtime scripts to `/usr/local/bin/sls_mass_notify`
- copies API endpoints to `/var/www/html/api/sipnotify` and `/var/www/html/api/sls-mass-notify`
- copies web assets to `/var/www/html/sls_mass_notify`
- creates the central config at `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config` on a fresh install and preserves it byte-for-byte during updates
- has shell, Python, PHP, and API services read the central config directly
- installs the local AMI user through the FreePBX Manager module, which generates `/etc/asterisk/manager_additional.conf`
- installs the direct audio and module-owned PJSIP auto-answer dialplan blocks in `/etc/asterisk/extensions_custom.conf`
- enables Apache directory access for the API/media paths
- installs the dashboard announcement widget compatibility files, rebuilds FreePBX Dashboard's persisted hook index, and verifies that the SIP NOTIFY announcement panel renders
- enforces `0640 asterisk:asterisk` on the protected central configuration after FreePBX ownership operations without changing its contents
- creates the Asterisk-owned one-minute weather scheduler for U.S. weather.gov zone groups; free-tier adaptive Lightning polling uses one selected zone as its storm gate and queries Xweather every five minutes only while that gate or its grace period is active
- installs bundled regular paging opening/closing tones, `NWS_alert.wav`, and `Lightning_alert.mp3` into FreePBX System Recordings and managed Asterisk audio
- verifies the real AMI contact-discovery action, Asterisk spool access, sound links, default audio formats, and exact paging dialplan before reporting success
- calls the local signing helper if one exists on the deployment

## First-Run Setup

After installing the module:

1. Open the FreePBX Dashboard or any page under **Mass Notifications**. New installs show the setup wizard as a modal overlay.
2. Read the beta/non-production warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0 license notice.
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
15. Add shared Weather and Lightning email recipients and an optional Discord webhook through the **Notification Destinations** popup in General Settings. Alert email is always sent as a branded Southland Servers HTML card with a plain-text fallback.
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

## Uninstall

The default uninstall preserves the central config, config backups, and uploaded tones. It removes the plugin-owned FreePBX Manager record and verifies that Apache/Asterisk artifacts are not regenerated and that Dashboard and Framework remain trusted. When FreePBX repository access is unavailable, it locally signs the cleaned stock modules, deletes the private key, retains only the public verification key, and prints the command that can later replace the fallback with vendor copies. Set `SLS_MASS_NOTIFY_PURGE_CONFIG=1` only when those deployment files should also be removed.

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```
