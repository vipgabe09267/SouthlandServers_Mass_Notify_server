# Installation Notes

## Requirements

- FreePBX 17
- Asterisk with PJSIP endpoints
- Apache/PHP as provided by FreePBX
- Python 3
- Piper TTS runtime. The installer creates `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv`, installs `piper-tts`, and downloads the configured Piper voices.
- `sox` for audio conversion and normalization
- `curl` for NWS/API calls

## Install Hooks

The module install hook prepares the local PBX integration by applying managed configuration only:

- copies runtime scripts to `/usr/local/bin/sls_mass_notify`
- copies API endpoints to `/var/www/html/api/sipnotify` and `/var/www/html/api/sls-mass-notify`
- copies web assets to `/var/www/html/sls_mass_notify`
- creates/updates the central config at `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`
- writes generated runtime files from the central config
- installs the local AMI user through the FreePBX Manager module, which generates `/etc/asterisk/manager_additional.conf`
- installs the direct audio dialplan block in `/etc/asterisk/extensions_custom.conf`
- enables Apache directory access for the API/media paths
- installs the dashboard announcement widget compatibility files
- creates the NWS polling cron line
- calls the local signing helper if one exists on the deployment

## First-Run Setup

After installing the module:

1. Open the FreePBX Dashboard or any page under **Mass Notifications**. New installs show the setup wizard as a modal overlay.
2. Read the beta/non-production warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0 license notice.
4. Read and accept the EULA.
5. Enable NWS only if you want weather alerts.
6. If NWS is enabled, enter your NWS zone/county code, for example `TXC491`.
7. Select NWS recipient extensions.
8. Configure quiet hours and critical bypass events.
9. Choose whether to enable the Control API. It is disabled by default.
10. Select the SIP NOTIFY phone-brand endpoints to expose.
11. Select the announcement TTS voice and NWS TTS voice.
12. Set announcement and NWS TTS volumes.
13. Set notification log retention.
14. Complete setup.

If Piper voices are missing after install, run:

```bash
/usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh
fwconsole reload
```

NWS zone codes can be found from weather.gov forecast and alert pages. County codes look like `TXC491`; forecast zones look like `TXZ163`.

`fwconsole ma install` cannot safely ask interactive questions, so the mandatory setup wizard is implemented as this first-run FreePBX UI modal. Leave NWS disabled if the deployment only needs manual announcements, desktop notifications, or SIP NOTIFY endpoints.

## Update Safety

Module code is installed under FreePBX modules. Runtime configuration is stored under:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin
```

Updates should not overwrite the central settings file. Use **Other Settings > Download .config** before major updates.

## FAQ

### Why is there no terminal wizard?

FreePBX module install hooks are expected to run non-interactively. The setup wizard is shown as a first-run modal when the Dashboard announcement widget or a Mass Notifications page is opened.

### What is the central config file?

The source of truth is:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

### What should be backed up?

Back up the central `.config` file. The UI also provides download/upload controls under **Other Settings > Danger Zone**.

### Can I install without NWS weather alerts?

Yes. Leave NWS disabled and use dashboard announcements, desktop notifications, SIP NOTIFY endpoints, and TTS audio.

### Does audio require a FreePBX paging group?

No. The module uses the private Asterisk context `sls-alert-audio`.

### How are credentials generated?

Install/normalization generates random API tokens, endpoint passwords, and AMI credentials when missing. These are stored in the central `.config`.
