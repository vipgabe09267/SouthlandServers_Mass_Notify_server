<p align="center">
  <a href="https://southlandservers.xyz">
    <img src="https://southlandservers.xyz/images/SLS_Mass_Notif_Plugin.png" width="180" alt="Southland Servers Mass Notifications Server">
  </a>
</p>

# Southland Servers Mass Notifications Server

An AGPL-3.0-or-later FreePBX 17 module for phone, desktop, weather, lightning, and audio-page notifications. It sends SIP NOTIFY messages directly through Asterisk/PJSIP, supports live authenticated desktop events, and can page tones or Piper TTS without a paging-group dependency.

Configuration lives in one protected, portable `.config` file outside the module tree. Weather.gov routing, optional Xweather lightning detection, announcement groups, schedules, display timeouts, API access, email and Discord delivery, tones, voices, and retention settings are managed from FreePBX.

Current release candidate: `0.0.9-beta`. This is beta software; test it on a non-critical FreePBX system before depending on it for emergency notifications.

## Install or update

Run as `root` on Debian 12 / FreePBX 17:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.0.9-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.0.9-beta/slsmassnotifyserver-0.0.9-beta.tgz' \
./sls-install.sh
```

Existing settings and credentials are preserved. Fresh installations open the required setup wizard. After installation, open **Mass Notify** in FreePBX.

## What It Installs

- FreePBX module raw name: `slsmassnotifyserver`
- FreePBX menu: `Mass Notify`
- Dashboard widget: `Mass Notify Announcements`
- Runtime scripts: `/usr/local/bin/sls_mass_notify`
- Scheduled-announcement worker: `/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php`
- Central data/config folder: `/var/lib/asterisk/SLS_Mass_Notifications_Plugin`
- Public media folder: `/var/www/html/sls_mass_notify`
- SIP Notify API: `/var/www/html/api/sipnotify`
- Control API: `/var/www/html/api/sls-mass-notify`
- Asterisk AMI user: `slsmassnotify`
- Asterisk direct audio context: `sls-alert-audio`
- Asterisk PJSIP auto-answer context: `sls-alert-autoanswer`

## Requirements

- FreePBX 17
- Debian 12
- Asterisk using PJSIP endpoints
- FreePBX Framework, Dashboard, and System Recordings modules
- Apache/PHP as provided by FreePBX, including the `/usr/bin/php` CLI and PHP OpenSSL, mbstring, and POSIX support
- Python 3 with `venv` and `pip`
- `curl`, `wget`, CA certificates, GnuPG, and `tar`
- SoX/soxi, ImageMagick, and DejaVu fonts
- cron, `flock`, `timeout`, `readlink`, and `runuser`
- Piper TTS. The installer creates a root-owned virtual environment under `/usr/local/bin/sls_mass_notify/piper`, exposes the compatibility path `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv`, installs pinned Piper packaging dependencies and `piper-tts`, and downloads checksum-verified voice models.

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
dist/slsmassnotifyserver-0.0.9-beta.tgz
```

## Install From A Local `.tgz`

Use this only if you already downloaded or built the release package and uploaded it to `/tmp/slsmassnotifyserver-0.0.9-beta.tgz` on the PBX.

```bash
cd /tmp
tar -tzf /tmp/slsmassnotifyserver-0.0.9-beta.tgz >/dev/null
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.0.9-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ=/tmp/slsmassnotifyserver-0.0.9-beta.tgz ./sls-install.sh
```

## Uninstall

This removes the FreePBX module, its Manager/AMI database user, runtime scripts, API folders, Apache state, module-owned System Recordings, sound symlinks, Dashboard hook, local signing artifacts, and temporary installer files. A bundled recording is removed only while its database metadata and audio hash still prove module ownership. The uninstaller then verifies that managed records and generated files are gone and that Dashboard and Framework remain trusted. It takes a protected temporary copy of the installed transactional signer before the module hook removes its normal copies. If the FreePBX module repository is unavailable, that copy signs the cleaned stock modules and is deleted before exit; older installations retain a compatibility fallback. Central config, backups, uploaded tones, and `schedule-executions.json` under `/var/lib/asterisk/SLS_Mass_Notifications_Plugin` are preserved during a normal uninstall so a reinstall cannot replay previously executed schedules. An explicit purge removes them.

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.0.9-beta/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```

## Post-Install Check

```bash
fwconsole ma list | grep -Ei 'slsmassnotifyserver|dashboard|Module'
asterisk -rx "dialplan show 1000@sls-alert-audio"
asterisk -rx "dialplan show s@sls-alert-autoanswer"
timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --ami-health-json
python3 -c 'compile(open("/usr/local/bin/sls_mass_notify/sls_notify.py", encoding="utf-8").read(), "sls_notify.py", "exec")'
php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php
curl -k -s -o /tmp/sls-control-api.out -w '%{http_code}' http://127.0.0.1/api/sls-mass-notify/
```


## First-Run Setup Flow

FreePBX module installs are not a safe place for interactive questions, so the mandatory setup wizard is implemented as a first-run modal in the FreePBX UI. Until the wizard is accepted, the Dashboard announcement widget and Mass Notifications module pages show the setup modal and keep controls locked.

1. Open any page under `Mass Notifications`.
2. Read the beta warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0-or-later license notice.
4. Read and accept the EULA.
5. Choose whether to configure Weather Alerts. The default is No; weather fields remain hidden unless Yes is selected.
6. If enabled, enter the first Weather.gov zone and its recipient extensions. Additional named zone groups can be added later from Weather Alerts.
7. Choose whether to configure Lightning Alerts. The default is No; Xweather fields remain hidden and are not validated unless Yes is selected.
8. Configure optional quiet hours, the Control API, phone delivery, and general paging audio.
9. Complete setup. The wizard writes the central `.config`; shell and Python services read that file directly.

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

That JSON `.config` file is the only settings source of truth. Shell, Python, PHP, API, desktop-client, announcement-group, NWS zone-group, Xweather, email, tone, TTS, and phone-format settings are normalized into it and runtime services read it directly. The canonical `mail_from_domain` value travels with this protected config; the runtime derives the alert sender as `no-reply@<mail_from_domain>`. Use the FreePBX UI or transplant the central `.config` file; obsolete generated `mass-notifications.conf` and `config.ini` copies are removed during install.

## Backup And Restore

Before major updates, download the central `.config` from:

```text
Mass Notify > General Settings > Danger Zone
```

To restore a deployment, upload the saved `.config` from the same page and apply changes. Replacing the config overwrites module settings such as endpoints, groups, tokens, voices, NWS settings, quiet hours, schedules, and log retention.

## APIs

Desktop notification API:

```text
https://pbx.example.com/api/sipnotify/desktop
```

Live desktop event stream:

```text
https://pbx.example.com/api/sipnotify/desktop/stream
```

Control API:

```text
https://pbx.example.com/api/sls-mass-notify
```

Desktop clients use their configured username and password. The live endpoint uses server-sent events with the same per-client targeting as the JSON endpoint, flushes its authenticated handshake through Apache immediately, supports `Last-Event-ID`, and asks clients to reconnect before the bounded PHP request ends. A desktop app should make a streaming HTTP request that can set the Basic `Authorization` header; the browser-only `EventSource` constructor cannot set that header. A legacy desktop that keeps requesting `/api/sipnotify/desktop` remains on the polling JSON fallback until that application is updated to use `/stream`. Notification records include flat presentation fields and a structured `presentation` object: Weather Alerts carry priority-derived background/header/accent/text colors, colored announcements retain the selected title and background, and Lightning publishes its branded warning color to the live desktop stream. For one phone family on an extension, Asterisk sends the vendor payload by endpoint and fans it out using that endpoint's own transports and contacts. Mixed-vendor contacts on one extension require complete contact URIs plus a configured Asterisk default outbound endpoint; otherwise delivery stops with a clear error instead of sending the wrong XML to every phone. The Control API is disabled by default and uses its own API key.

`Mass Notify > General Settings > Public PBX Hostname` is automatically detected and displayed read-only; it is not accepted from settings forms or Control API configuration patches. Phone Image Transport defaults to HTTP for legacy Yealink compatibility and can be changed to HTTPS when every target phone trusts the PBX certificate and supports its TLS configuration. Authenticated APIs remain HTTPS.

If a phone vendor is detected incorrectly, open the **Phone Format Overrides** manager under `Mass Notify > General Settings`. Enter the extension, choose one of the supported phone families from the dropdown, and save. Automatic detection remains the default for extensions without an override.

## Logs

Primary logs:

```text
/var/log/sls_mass_notify.log
/var/log/sls_mass_notify_events.jsonl
/var/log/sls_mass_notify_push.log
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sipnotify/sipnotify_events.jsonl
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json
```

Log retention is configured in `Mass Notify > General Settings`; default retention is 90 days, maximum is 365 days. The Notification Logs page can combine event-type, PBX-local calendar-date, and row-limit filters, with a one-click filter reset.

## Validation Commands

Useful checks after install are also listed above. The most common quick check is:

```bash
fwconsole ma list | grep -Ei 'slsmassnotifyserver|dashboard|Module'
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

NWS appears only for the **Weather Alerts** portion of the module: API details, U.S. weather.gov zone groups, recipients, weather TTS voice, and technical logs. Weather Alerts supports United States weather.gov zones only. The overall product remains Southland Servers Mass Notifications Server.

### How are announcements delivered?

The dashboard announcement widget can send SIP NOTIFY text/image payloads, publish to desktop clients, and independently use no audio, tones only, TTS only, or tones plus TTS. Opening and closing System Recordings can be selected per send.

### How does Scheduling work?

Open **Mass Notify > Scheduling** and create a schedule with one or more future PBX-local dates and times. Each schedule stores its phone, announcement-group, desktop, audio, voice, volume, tone, and optional Labs color choices in the protected central config. The Asterisk-owned worker checks once per minute. Pre-delivery cooldown, busy, or temporarily offline target conditions can retry inside a 15-minute grace window; a delivery already claimed by an interrupted worker is marked for review instead of being replayed.

Scheduled pages use the PBX operating-system timezone. Nonexistent or ambiguous daylight-saving times are rejected, and Dashboard health warns when FreePBX's PHP timezone differs. Scheduling is serialized with ordinary announcements, so items placed closer together than the configured cooldown can run late and remain eligible only inside the protected grace window. To re-arm a failed or missed occurrence, remove that date and add a new future date; an uncertain occurrence must be reviewed and is never replayed automatically.

Configuration exports contain schedule definitions but not the PBX-local execution ledger. Imports and FreePBX restores therefore disable imported schedules automatically; review dates, targets, and delivery settings before enabling them on the destination PBX.

Times that do not exist during a daylight-saving transition, or that occur twice during the fall transition, are rejected so the delivery instant is never ambiguous.

### Can regular announcement screens expire?

Yes. **General Settings > Announcement Timeout** defaults to **No expiry**. It can instead follow the generated page-audio duration or use a fixed 1–86,400 second timeout. Regular Yealink text/image payloads receive the XML timeout, and live desktop events receive timeout and UTC expiry metadata. Other phone families depend on their own firmware behavior; Weather Alert expiry fields are not changed by this setting.

### Is the setup wizard mandatory?

Yes. New installs show a setup modal on the Dashboard announcement widget and Mass Notifications pages until the beta warning, AGPL notice, EULA, and first-run configuration are accepted. Existing deployments keep their central `.config` and are not forced through the wizard during an update.

### Do phones need separate API endpoints?

No. Phones receive SIP NOTIFY directly from Asterisk/PJSIP. Desktop clients use `/api/sipnotify/desktop/stream` for live events or `/api/sipnotify/desktop` for JSON fallback with their assigned username and password.

### Can I regenerate credentials?

Yes. The Control API key can be regenerated, and desktop clients can be given new per-client usernames/passwords from the FreePBX UI. Fresh installs generate random Control API, desktop encryption, desktop client, and AMI credentials. Updates preserve credentials in the central `.config` unless an administrator intentionally changes them.

### Can I change the alert email domain?

Yes. Open **Mass Notify > General Settings > Notification Destinations** and set **Email Sender Domain**. The module keeps the local part fixed as `no-reply`; for example, changing `pbx.example.com` to `example.com` changes the sender from `no-reply@pbx.example.com` to `no-reply@example.com`. Fresh installs default to the detected PBX hostname. Upgrades without `mail_from_domain` retain their existing valid sender domain by deriving it from the legacy `mail_from_addr` value. The canonical value follows protected config backups and is available to validated, allowlisted Control API configuration updates.

This setting changes only the sender address used by Mass Notify alert messages. It does not configure Postfix, an SMTP relay, DNS, SPF, DKIM, DMARC, or PTR/reverse DNS. Configure those separately so the selected domain is authorized to send mail from this PBX.

### Does the Control API allow remote control?

Only if enabled. It is disabled by default and protected by its own API key.

### How do automatic updates work?

Automatic updates are disabled by default. When enabled, a root-owned job checks only the official beta repository, accepts release assets that include a GitHub SHA-256 digest, downloads the installer from the matching immutable release tag, and supplies the expected digest to the installer. The Asterisk service account cannot replace the updater or Piper executable runtime.

Update availability is checked even when automatic installation is disabled. General Settings and Dashboard health show a yellow warning when a newer beta is available. **Update to Latest Release** appears only while a strictly newer beta is available. After it is clicked, General Settings shows queued, checking, and installing progress, displays a completion checkmark, and refreshes to the installed version; failures remain visible instead of silently disappearing.

### Are all phone models guaranteed to display SIP NOTIFY payloads?

No. The module implements documented XML families and detects registered-contact User-Agents, but actual behavior depends on model, firmware, XML push provisioning, SIP NOTIFY authentication, and HTTPS certificate trust. Yealink XML push must be enabled on the handset or through provisioning; the PBX installer does not change phone firmware or provisioning. Use a manual format override when detection is wrong, choose **Yealink - Text Only** when a Yealink cannot retrieve an image, and test every target model before emergency use. The override manager also includes **Yealink - Color** and Panasonic KX. Unknown endpoints are flagged automatically but are not offered as a manual format. Use separate extensions for mixed vendors unless the PBX has a working default outbound endpoint for per-contact URI delivery.

### What happens during quiet hours?

Live Weather Alerts can be suppressed unless the event is configured as critical. Lightning Alerts has its own independent quiet-hours on/off toggle and schedule. Dashboard announcements warn the user if Weather Alert quiet hours are active.

### Can I use my own tones?

Yes. Upload audio through **Admin > System Recordings**, then select it as an opening or closing tone in General Settings, Weather Alerts, Lightning Alerts, Scheduling, or a dashboard announcement. Either tone can be **None** without changing or breaking `extensions_custom.conf`. The installer registers four ownership-safe recordings: **SLS Mass Notify - Paging Tone Opening**, **SLS Mass Notify - Paging Tone Closing**, **SLS Mass Notify - NWS Alert**, and **SLS Mass Notify - Lightning Alert**. It refuses a conflicting user-owned recording instead of overwriting it. Fresh regular announcements use both paging tones, Weather Alerts use the NWS tone with no closing tone, and Lightning uses the Lightning tone with no closing tone; all three profiles default to 25% volume. Tone-only mode pages sounds without speaking the typed text.

Generated TTS and combined announcement WAV files are automatically removed after 15 minutes.

### Can different NWS zones notify different phones?

Yes. Configure up to five named NWS zone groups and select extension recipients independently for each group. Manual NWS tests can target all configured groups or selected groups.

### How do Xweather lightning alerts work?

The dedicated **Lightning Alerts** tab uses Xweather `lightning/closest` for cloud-to-ground strikes. Administrators configure protected API credentials, a location, radius, recipients, independent quiet hours, optional all-clear delivery, opening/closing tones, and a 1–200% Lightning TTS volume that defaults to 25%. Coordinate locations are spoken naturally as “this area” instead of reading latitude/longitude aloud, while named locations use the configured city. Each combined Lightning page retains one second of leading silence before its pre-tone and speech.

The default-on **Adaptive protection** toggle requires one selected Weather Alert zone. Its enabled state is shown with a green shield; Xweather stays in zero-token standby until that specific Weather.gov zone reports an active thunderstorm event, then polls every five minutes through a configurable 5–120 minute grace period that defaults to 60 minutes. A protected token bucket starts with one day of allowance, banks up to seven days, and limits scheduled queries to at most 15,000 tokens across 30 days. The optimized nearest-strike query observed on the production PBX costs 10 tokens, so continuous five-minute polling would require about 86,400 tokens per 30 days. Adaptive protection preserves the allowance by trading coverage: lightning outside an NWS event can be missed, and a long storm can temporarily enter quota-guard standby. Turning it off changes the state to a red shield and polls continuously at the configured 1–10 minute period regardless of NWS conditions; 1–4 minute choices display a hazard warning, and periods above 5 minutes can miss strikes because standard Xweather access covers only the recent five-minute window.

The Lightning page presents that enabled/disabled state once beside the toggle. A separate quota warning appears only when adaptive protection is disabled and the observed continuous schedule exceeds the account allowance.

One warning is sent when a storm first enters the radius. The warning uses Xweather's nearest-strike distance and reports it to one decimal mile (for example, 4.1 miles) rather than reading the configured radius as the strike distance. Additional strikes from that active cluster do not create repeat alerts. Two consecutive clear queries reset the state; an optional all-clear can be sent, and a later storm can then generate a new warning. Credentials remain only in the protected central config and are redacted from diagnostics and Control API responses. The UI links to the official Xweather key setup guide; see the [Xweather Lightning API documentation](https://www.xweather.com/docs/weather-api/endpoints/lightning).

The Lightning system test has its own 60-second anti-spam cooldown. Test phone, audio, and desktop content is explicitly labeled **TEST ONLY** so a validation cannot be mistaken for a real lightning event. Tests do not send email or Discord notifications. The saved Client Secret is masked in Lightning Alerts and can be revealed by an authenticated FreePBX administrator with the eye button; diagnostics and APIs never return it.

Settings use FreePBX’s standard top-right **Apply Config** control. Saving a module form stages the protected central configuration and marks FreePBX for reload; the native config hook atomically applies it. Install and repair rebuild FreePBX Dashboard's stored hook index and verify that the announcement panel renders. A root maintenance check restores the managed Dashboard widget and menu placement after Dashboard or Framework replacement and corrects the central config to `0640 asterisk:asterisk` without rewriting its contents. Install, update, repair, and uninstall share the same root-owned maintenance lock so the minute worker cannot rewrite integration files during a deployment transaction. The menu repair supports both the numeric comparator used by earlier Framework 17 builds and the boolean comparator introduced by Framework 17.0.30.

### What do alert emails look like?

Shared email recipients, the sender domain, and the optional Discord webhook are managed from the **Notification Destinations** popup in General Settings. When recipients are configured, Weather and Lightning alerts always use a branded multipart message. The HTML card embeds a compact Southland Servers logo inside the email, identifies Southland Servers Group and the SLS Mass Notification System, highlights the primary action, and renders structured alert details. Tornado, severe-storm, flood, winter, fire, lightning, test, and general alerts receive distinct urgency colors and icons. A plain-text alternative is included for non-HTML clients. Discord uses the same event-aware visual language in a smaller embed with the SLS identity, a public logo image/avatar, concise delivery fields, timestamp, and urgency/test footer; its logo URL is built from the automatically detected PBX hostname.

### Where do I report bugs?

Use GitHub Issues:

```text
https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
```

## Support

Project information: https://southlandservers.xyz/projects

Discord: https://southlandservers.xyz/discord

Issues: https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
