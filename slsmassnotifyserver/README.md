# Southland Servers Mass Notifications Server

![Southland Servers Mass Notifications Server](https://southlandservers.xyz/images/SLS_Mass_Notif_Plugin.png)

Southland Servers Mass Notifications Server is an open-source AGPLv3 FreePBX module that turns a PBX into a centralized alerting and mass notification server. It provides a unified interface for sending SIP NOTIFY visual alerts, desktop app notifications, dashboard announcements, NWS weather alerts, and optional Piper TTS audio paging through Asterisk.

The system stores its configuration in a transplantable central `.config` file and supports authenticated APIs, Asterisk/PJSIP phone delivery, per-client desktop credentials, announcement groups, quiet hours, notification log retention, uploaded tones, installer-downloaded Piper voices, and NWS zone settings. It is designed for organizations that want PBX-integrated EAS-style notifications without depending on a closed vendor platform, while keeping phone delivery, desktop clients, weather alerting, and alert history manageable from FreePBX.

Current public beta release: `0.0.5-beta`.

## Status

Pre-release beta staging. Review and test on a non-critical FreePBX 17 system before production use.

## What It Installs

- FreePBX module raw name: `slsmassnotifyserver`
- FreePBX menu: `Mass Notify`
- Dashboard widget: `SIP NOTIFY Announcements`
- Runtime scripts: `/usr/local/bin/sls_mass_notify`
- Central data/config folder: `/var/lib/asterisk/SLS_Mass_Notifications_Plugin`
- Public media folder: `/var/www/html/sls_mass_notify`
- SIP Notify API: `/var/www/html/api/sipnotify`
- Control API: `/var/www/html/api/sls-mass-notify`
- Asterisk AMI user: `slsmassnotify`
- Asterisk direct audio context: `sls-alert-audio`

## Requirements

- FreePBX 17
- Asterisk using PJSIP endpoints
- Apache/PHP as provided by FreePBX
- Python 3
- `curl`
- `sox`
- Piper TTS. The installer creates a root-owned virtual environment under `/usr/local/bin/sls_mass_notify/piper`, installs pinned Piper packaging dependencies and `piper-tts`, and downloads checksum-verified voice models.

Piper voices downloaded during install:

- `en_US-lessac-low`
- `en_US-amy-low`
- `en_US-ryan-low`

The installer downloads these voice models from the Piper voice repository instead of storing large `.onnx` model files in this source repository or module package.

If a PBX cannot download voices during install, rerun the built-in repair command after restoring internet/DNS access:

```bash
/usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh
fwconsole reload
```

## Build

From the repository root:

```bash
./tools/build_tgz.sh
```

The package is written to:

```text
dist/slsmassnotifyserver-0.0.5-beta.tgz
```

## Install

### **Recommended** Release Installer

Run this on the FreePBX server as `root` or a sudo-capable administrator.

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.5-beta/slsmassnotifyserver-0.0.5-beta.tgz' \
./sls-install.sh
```

After install, open FreePBX and go to `Mass Notifications`. New installs show the required first-run setup wizard before the module controls can be used.

Expected module state:

```text
slsmassnotifyserver Enabled
```

Custom/local FreePBX module signatures normally show as `Unknown`. That is acceptable for this beta package. `Altered` means the module should be signed again on that PBX.

## Install From A Local `.tgz`

Use this only if you already downloaded or built the release package and uploaded it to `/tmp/slsmassnotifyserver-0.0.5-beta.tgz` on the PBX.

```bash
cd /tmp
tar -tzf /tmp/slsmassnotifyserver-0.0.5-beta.tgz >/dev/null
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ=/tmp/slsmassnotifyserver-0.0.5-beta.tgz ./sls-install.sh
```

## Uninstall

This removes the FreePBX module, its Manager/AMI database user, runtime scripts, API folders, Apache state, sound symlinks, local signing artifacts, and temporary installer files. It then verifies that managed records and generated files are gone and that the stock Dashboard and Framework signatures are valid. Central config files under `/var/lib/asterisk/SLS_Mass_Notifications_Plugin` are preserved when present so the deployment can be restored later.

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```

## Post-Install Check

```bash
fwconsole ma list | egrep -i 'slsmassnotifyserver|dashboard|Module'
asterisk -rx "dialplan show 1000@sls-alert-audio"
python3 -c 'compile(open("/usr/local/bin/sls_mass_notify/sls_notify.py", encoding="utf-8").read(), "sls_notify.py", "exec")'
php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php
curl -k -s -o /tmp/sls-control-api.out -w '%{http_code}' http://127.0.0.1/api/sls-mass-notify/
```


## First-Run Setup Flow

FreePBX module installs are not a safe place for interactive questions, so the mandatory setup wizard is implemented as a first-run modal in the FreePBX UI. Until the wizard is accepted, the Dashboard announcement widget and Mass Notifications module pages show the setup modal and keep controls locked.

1. Open any page under `Mass Notifications`.
2. Read the beta warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0 license notice.
4. Read and accept the EULA.
5. Choose whether to enable the NWS weather alert system.
6. If NWS is enabled, enter the zone or county code and choose NWS recipient extensions.
7. Configure quiet hours and critical bypass events.
8. Choose whether to enable the Control API. A random API key is generated when needed.
9. Configure desktop app clients and phone delivery defaults. Phone SIP NOTIFY is sent directly through Asterisk/PJSIP; no per-brand API endpoint setup is required.
10. Select announcement and NWS Piper voices.
11. Set announcement and NWS TTS volumes.
12. Set notification log retention.
13. Complete setup. The wizard writes the central `.config`; shell and Python services read that file directly.

NWS zone/county examples:

- County code: `TXC491`
- Forecast zone: `TXZ163`

NWS zone/county codes can be found from the official NWS GIS Zone/County page:

```text
https://www.weather.gov/gis/ZoneCounty
```

## Runtime Config

Live configuration is stored outside the module so updates do not overwrite it:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

That JSON `.config` file is the only settings source of truth. Shell, Python, PHP, API, desktop-client, announcement-group, NWS, TTS, and phone-format settings are normalized into it and runtime services read it directly. Use the FreePBX UI or transplant the central `.config` file; obsolete generated `mass-notifications.conf` and `config.ini` copies are removed during install.

## Backup And Restore

Before major updates, download the central `.config` from:

```text
Mass Notify > General Settings > Danger Zone
```

To restore a deployment, upload the saved `.config` from the same page and apply changes. Replacing the config overwrites plugin settings such as endpoints, groups, tokens, voices, NWS settings, quiet hours, and log retention.

## APIs

Desktop notification API:

```text
https://pbx.example.com/api/sipnotify/desktop
```

Control API:

```text
https://pbx.example.com/api/sls-mass-notify
```

Desktop clients use their configured username and password. Phone SIP NOTIFY payloads are pushed by Asterisk/PJSIP to registered endpoints, and the sender chooses the payload style from the detected endpoint vendor. The Control API is disabled by default and uses its own API key.

If generated API or image links show a short hostname such as `https://pbx/...`, set `Mass Notify > General Settings > Public PBX Hostname` to the real DNS name clients and phones can reach. Phone Image Transport defaults to HTTP for legacy Yealink compatibility and can be changed to HTTPS when every target phone trusts the PBX certificate and supports its TLS configuration. Authenticated APIs remain HTTPS.

If a phone vendor is detected incorrectly, use `Mass Notify > General Settings > Phone Format Overrides` with one mapping per line:

```text
1190=cisco
1000=yealink
```

## Logs

Primary logs:

```text
/var/log/sls_mass_notify.log
/var/log/sls_mass_notify_events.jsonl
/var/log/sls_mass_notify_push.log
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json
```

Log retention is configured in `Mass Notify > General Settings`; default retention is 90 days, maximum is 365 days.

## Validation Commands

Useful checks after install are also listed above. The most common quick check is:

```bash
fwconsole ma list | egrep -i 'slsmassnotifyserver|dashboard|Module'
```

## FAQ

### Is this production ready?

This is beta software. It is designed to be update-safe and production-oriented, but it should be tested on a non-critical PBX before live emergency use.

### Where are settings stored?

The source of truth is:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

Runtime services read and validate that central file directly.

### Will updates overwrite my settings?

Module updates should not overwrite the central `.config` file. Back it up before major upgrades anyway.

### Why does NWS still appear in some places?

NWS appears only for the weather-alert portion of the plugin: NWS API URL, NWS zone, NWS alert recipients, NWS TTS voice, and weather-specific logs or labels.

### How are announcements delivered?

The dashboard announcement widget can send SIP NOTIFY text/image payloads, publish to the desktop app API, and optionally queue Piper TTS audio first.

### Is the setup wizard mandatory?

Yes. New installs show a setup modal on the Dashboard announcement widget and Mass Notifications pages until the beta warning, AGPL notice, EULA, and first-run configuration are accepted. Existing deployments keep their central `.config` and are not forced through the wizard during an update.

### Do phones need separate API endpoints?

No. Phones receive SIP NOTIFY directly from Asterisk/PJSIP. Desktop clients use `/api/sipnotify/desktop` with their assigned username and password.

### Can I regenerate credentials?

Yes. The Control API key can be regenerated, and desktop clients can be given new per-client usernames/passwords from the FreePBX UI. Fresh installs generate random Control API, desktop encryption, desktop client, and AMI credentials. Updates preserve credentials in the central `.config` unless an administrator intentionally changes them.

### Does the Control API allow remote control?

Only if enabled. It is disabled by default and protected by its own API key.

### How do automatic updates work?

Automatic updates are disabled by default. When enabled, a root-owned job checks only the official beta repository, accepts release assets that include a GitHub SHA-256 digest, downloads the installer from the matching immutable release tag, and supplies the expected digest to the installer. The Asterisk service account cannot replace the updater or Piper executable runtime.

### Are all phone models guaranteed to display SIP NOTIFY payloads?

No. The module implements documented XML families and detects registered-contact User-Agents, but actual behavior depends on model, firmware, XML push provisioning, SIP NOTIFY authentication, and HTTPS certificate trust. Use a manual format override when detection is wrong, use `yealink_text` when a Yealink cannot retrieve an image, and test every target model before emergency use. Mixed-vendor phones sharing one extension receive endpoint-level pushes and should be tested especially carefully.

### What happens during quiet hours?

Live NWS alerts can be suppressed unless the event is configured as critical. Dashboard announcements warn the user if quiet hours are active.

### Can I use my own tones?

Yes. Opening and closing tones can be uploaded in the UI. Tones are stored under the SLS plugin data folder and are used with Piper TTS audio.

### Where do I report bugs?

Use GitHub Issues:

```text
https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
```

## Support

Project information: https://southlandservers.xyz/projects

Discord: https://southlandservers.xyz/discord

Issues: https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
