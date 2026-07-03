# Southland Servers Mass Notifications Server

![Southland Servers Mass Notifications Server](https://southlandservers.xyz/images/SLS_Mass_Notif_Plugin.png)

Southland Servers Mass Notifications Server is an open-source AGPLv3 FreePBX module that turns a PBX into a centralized alerting and mass notification server. It provides a unified interface for sending SIP NOTIFY visual alerts, desktop app notifications, dashboard announcements, NWS weather alerts, and optional Piper TTS audio paging through Asterisk.

The system stores its configuration in a transplantable central `.config` file and supports token-protected APIs, configurable phone-brand endpoints, announcement groups, quiet hours, notification log retention, uploaded tones, installer-downloaded Piper voices, and NWS zone settings. It is designed for organizations that want PBX-integrated EAS-style notifications without depending on a closed vendor platform, while keeping phone delivery, desktop clients, weather alerting, and alert history manageable from FreePBX.

Current public beta release: `0.0.2-beta`.

## Status

Pre-release beta staging. Review and test on a non-critical FreePBX 17 system before production use.

## What It Installs

- FreePBX module raw name: `slsmassnotifyserver`
- FreePBX menu: `Mass Notifications`
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
- Piper TTS. The installer creates the local Piper virtualenv, installs `piper-tts`, and downloads the configured voices.

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
dist/slsmassnotifyserver-0.0.2-beta.tgz
```

## Install

Run this on the FreePBX server as `root` or a sudo-capable administrator.

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.2-beta/slsmassnotifyserver-0.0.2-beta.tgz' \
./sls-install.sh
```

After install, open FreePBX and go to `Mass Notifications`. New installs show the required first-run setup wizard before the module controls can be used.

Expected module state:

```text
slsmassnotifyserver Enabled
```

Custom/local FreePBX module signatures normally show as `Unknown`. That is acceptable for this beta package. `Altered` means the module should be signed again on that PBX.

## Install From A Local `.tgz`

Use this only if you already downloaded or built the release package and uploaded it to `/tmp/slsmassnotifyserver-0.0.2-beta.tgz` on the PBX.

```bash
cd /tmp
tar -tzf /tmp/slsmassnotifyserver-0.0.2-beta.tgz >/dev/null
rm -rf /var/www/html/admin/modules/slsmassnotifyserver
tar -xzf /tmp/slsmassnotifyserver-0.0.2-beta.tgz -C /var/www/html/admin/modules/
fwconsole ma install slsmassnotifyserver
/usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh
fwconsole reload
asterisk -rx "dialplan reload"
```

## Uninstall

This removes the FreePBX module but leaves the central `.config` and runtime data in place so the deployment can be restored later.

```bash
fwconsole ma uninstall slsmassnotifyserver || true
fwconsole ma delete slsmassnotifyserver || true
fwconsole reload
```

## Post-Install Check

```bash
fwconsole ma list | egrep -i 'slsmassnotifyserver|dashboard|Module'
asterisk -rx "dialplan show 1000@sls-alert-audio"
python3 -m py_compile /usr/local/bin/sls_mass_notify/sls_notify.py
php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php
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
9. Choose the SIP NOTIFY endpoints/phone brands this PBX should expose.
10. Select announcement and NWS Piper voices.
11. Set announcement and NWS TTS volumes.
12. Set notification log retention.
13. Complete setup. The wizard writes the central `.config`, generated shell config, and Python runtime config.

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

That JSON `.config` file is the source of truth. The module generates runtime files from it:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.conf
/usr/local/bin/sls_mass_notify/config.ini
```

Do not edit generated runtime files as the primary configuration method. Use the FreePBX UI or transplant the central `.config` file.

## Backup And Restore

Before major updates, download the central `.config` from:

```text
Mass Notifications > Other Settings > Danger Zone
```

To restore a deployment, upload the saved `.config` from the same page and apply changes. Replacing the config overwrites plugin settings such as endpoints, groups, tokens, voices, NWS settings, quiet hours, and log retention.

## APIs

Desktop/SIP Notify API:

```text
https://pbx.example.com/api/sipnotify
https://pbx.example.com/api/sipnotify/desktop
https://pbx.example.com/api/sipnotify/yealink
```

Control API:

```text
https://pbx.example.com/api/sls-mass-notify
```

The desktop endpoint uses the SLS Mass Notify App token. Phone-brand endpoints use their configured username/password. The Control API is disabled by default and uses its own API key.

## Logs

Primary logs:

```text
/var/log/sls_mass_notify.log
/var/log/sls_mass_notify_events.jsonl
/var/log/sls_mass_notify_push.log
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json
```

Log retention is configured in `Mass Notifications > Other Settings`; default retention is 90 days, maximum is 365 days.

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

Generated shell and Python config files are rebuilt from that central file.

### Will updates overwrite my settings?

Module updates should not overwrite the central `.config` file. Back it up before major upgrades anyway.

### Why does NWS still appear in some places?

NWS appears only for the weather-alert portion of the plugin: NWS API URL, NWS zone, NWS alert recipients, NWS TTS voice, and weather-specific logs or labels.

### How are announcements delivered?

The dashboard announcement widget can send SIP NOTIFY text/image payloads, publish to the desktop app API, and optionally queue Piper TTS audio first.

### Is the setup wizard mandatory?

Yes. New installs show a setup modal on the Dashboard announcement widget and Mass Notifications pages until the beta warning, AGPL notice, EULA, and first-run configuration are accepted. Existing deployments keep their central `.config` and are not forced through the wizard during an update.

### How do phone-brand endpoints authenticate?

The desktop app endpoint uses a bearer token. Phone-brand endpoints use configured username/password credentials.

### Can I regenerate credentials?

Yes. The SLS Mass Notify App token and Control API key can be regenerated from the FreePBX UI. Fresh installs automatically generate new random credentials during setup; updates keep existing credentials from the central `.config` file unless you intentionally regenerate them. Regenerating either credential requires updating clients that use it.

### Does the Control API allow remote control?

Only if enabled. It is disabled by default and protected by its own API key.

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
