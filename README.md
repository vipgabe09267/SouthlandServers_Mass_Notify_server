<p align="center">
  <a href="https://southlandservers.xyz">
    <img src="https://southlandservers.xyz/images/SLS_Mass_Notif_Plugin.png" width="132" alt="Southland Servers Mass Notifications Server">
  </a>
</p>

# Southland Servers Mass Notifications Server

An AGPL-3.0-or-later FreePBX 17 module for phone, desktop, weather, lightning, and audio-page notifications. It sends SIP NOTIFY messages directly through Asterisk/PJSIP, supports live authenticated desktop events, and can page tones or Piper TTS without a paging-group dependency.

Configuration lives in one protected, portable `.config` file outside the module tree. Weather zones independently select phones, desktops, email recipients, Discord webhooks, generic HTTPS webhooks, and quiet-hour behavior. Lightning areas independently select phones, desktops, email recipients, strike type, and quiet-hour behavior. Announcement groups, schedules, display timeouts, API access, Postfix sender and system/error email settings, tones, voices, and retention settings are also managed from FreePBX.

Current release: `0.1.1-beta`. The project is still beta-stage software; test it on a non-critical FreePBX system before depending on it for emergency notifications.

## Install or update

Run as `root` on Debian 12 / FreePBX 17:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.1-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.1-beta/slsmassnotifyserver-0.1.1-beta.tgz' \
./sls-install.sh
```

Existing settings and credentials are preserved. Fresh installations open the required setup wizard. After installation, open **Mass Notify** in FreePBX.

The installer reports the PBX operating-system timezone before activation. An interactive install can keep it or choose another IANA timezone; unattended installs never pause and can request one explicitly with `SLS_MASS_NOTIFY_TIMEZONE=America/Chicago`. If a later installation step rolls back, an installer-made timezone change is rolled back too.

## What It Installs

- FreePBX module raw name: `slsmassnotifyserver`
- FreePBX menu: `Mass Notify`
- Dashboard widget: `Mass Notify Announcements`
- Runtime scripts: `/usr/local/bin/sls_mass_notify`
- Scheduled-announcement worker: `/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php`
- Central data/config folder: `/var/lib/asterisk/SLS_Mass_Notifications_Plugin` (the historical path is retained for upgrade compatibility)
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
- FreePBX Framework, Dashboard, Backup & Restore, and System Recordings modules
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
dist/slsmassnotifyserver-0.1.1-beta.tgz
```

## Install From A Local `.tgz`

Use this only if you already downloaded or built the release package and uploaded it to `/tmp/slsmassnotifyserver-0.1.1-beta.tgz` on the PBX.

```bash
cd /tmp
tar -tzf /tmp/slsmassnotifyserver-0.1.1-beta.tgz >/dev/null
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.1-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ=/tmp/slsmassnotifyserver-0.1.1-beta.tgz ./sls-install.sh
```

## Uninstall

This removes the FreePBX module, its Manager/AMI database user, runtime scripts, API folders, Apache state, module-owned System Recordings, sound symlinks, Dashboard hook, local signing artifacts, and temporary installer files. A bundled recording is removed only while its database metadata and audio hash still prove module ownership. The uninstaller then verifies that managed records and generated files are gone and that Dashboard and Framework remain trusted. It takes a protected temporary copy of the installed transactional signer before the module hook removes its normal copies. If the FreePBX module repository is unavailable, that copy signs the cleaned stock modules and is deleted before exit; older installations retain a compatibility fallback. Central config, backups, uploaded tones, and `schedule-executions.json` under `/var/lib/asterisk/SLS_Mass_Notifications_Plugin` are preserved during a normal uninstall so a reinstall cannot replay previously executed schedules. An explicit purge removes them.

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.1-beta/tools/uninstall_release.sh
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

FreePBX module installs are not a safe place for interactive questions, so the mandatory setup wizard is implemented as a first-run modal in the FreePBX UI. Until the wizard is accepted, the Dashboard announcement widget and Mass Notify pages show the setup modal and keep controls locked.

1. Open any page under `Mass Notify`.
2. Read the beta warning and accept the at-your-own-risk acknowledgement.
3. Review and accept the AGPL-3.0-or-later license notice.
4. Read and accept the EULA.
5. Choose whether to configure Weather Alerts. The default is No; weather fields remain hidden unless Yes is selected.
6. If enabled, enter the first Weather.gov zone and select at least one phone extension or enabled desktop client. Optional email recipients receive live alerts from that zone only. Additional named zone groups can be added later from Weather Alerts.
7. Choose whether to configure Lightning Alerts. The default is No; Xweather fields remain hidden and are not validated unless Yes is selected.
8. Configure optional quiet hours, the Control API, phone delivery, and general paging audio.
9. Complete setup. The wizard writes the central `.config`; shell and Python services read that file directly.

To find a Weather.gov forecast zone, open the [official NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone), choose the state, and find the three-digit zone number covering the location. Enter the two-letter state abbreviation, the letter `Z`, and that number. For example, Texas zone `163` is `TXZ163`.

## Runtime Config

Live configuration is stored outside the module so updates do not overwrite it:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

That JSON `.config` file is the only settings source of truth. Shell, Python, PHP, API, desktop-client, announcement-group, NWS zone-group, Xweather, destination, tone, TTS, and phone-format settings are normalized into it and runtime services read it directly. The sender local part and domain travel with this protected config; a fresh install starts with `no-reply` and derives the domain from the local Postfix/PBX identity. Use the FreePBX UI or transplant the central `.config` file; obsolete generated `mass-notifications.conf` and `config.ini` copies are removed during install.

## Backup And Restore

Version 0.1.1-beta includes a native FreePBX 17 backup adapter. Module-based FreePBX backup jobs can include the protected config, the schedule execution journal, and custom module tones. Restore validates the manifest, size, hashes, config structure, credentials, and WAV content before replacing live data. Due or completed schedule occurrences are not replayed, and post-restore integration repair is verified separately.

Custom modules are not fetched automatically by a stock FreePBX restore. Install this module on a replacement PBX before restoring an archive that contains its data. The installer enables the FreePBX Backup prerequisite, verifies native-adapter discovery, and adds Mass Notify to existing module-based jobs. A fresh PBX with no backup jobs is ready rather than faulty; create a job in **Backup & Restore** when you are ready to choose its schedule, storage, and retention policy. Review that job before relying on it.

Also keep a separate copy of the central `.config`. Download it before major updates from:

```text
Mass Notify > General Settings > Danger Zone
```

To restore only the module configuration, upload the saved `.config` from the same page and apply changes. Replacing it overwrites module settings such as endpoints, groups, tokens, destinations, voices, NWS settings, quiet hours, schedules, and log retention.

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

Desktop clients use their configured username and password. The live endpoint uses server-sent events with the same per-client targeting as the JSON endpoint, flushes its authenticated handshake through Apache immediately, supports `Last-Event-ID`, and asks clients to reconnect before the bounded PHP request ends. A desktop app should make a streaming HTTP request that can set the Basic `Authorization` header; the browser-only `EventSource` constructor cannot set that header. A legacy desktop that keeps requesting `/api/sipnotify/desktop` remains on the polling JSON fallback until that application is updated to use `/stream`. Dashboard announcements and manual Weather tests publish the selected desktop event independently of phone work. Live Weather and Lightning delivery writes the desktop journal immediately after accepting the local dispatch/audio queue, while only handset SIP NOTIFY keeps the two-second page-first delay. Notification records include flat presentation fields and a structured `presentation` object: Weather Alerts carry priority-derived background/header/accent/text colors, colored announcements retain the selected title and background, and Lightning publishes its branded warning color to the live desktop stream. The Control API is disabled by default and uses its own API key.

Desktop presence distinguishes a current stream, a recent disconnect, and a sleeping or offline client. A sleeping device is not reported as live merely because it connected earlier. Authorized journal records can be received after reconnection only while their target and expiry rules still allow them.

Audio paging uses Asterisk `Page()`/ConfBridge with every resolved PJSIP contact so a softphone registration does not displace a desk phone registration. Weather Alert pages use a bounded serialized audio queue, preventing simultaneous NWS alerts from overlapping. Within a feed, alerts are ordered by their NWS time and then advisory, watch, and warning; configured zones take deterministic turns. A protected cross-zone claim journal prevents an overlapping alert chain from reaching the same phone, desktop, email address, Discord webhook, or generic webhook twice while preserving unique destinations in each zone. The module submits visual payloads separately through AMI. Mixed phone families receive contact-specific payloads when every contact URI can be routed; otherwise Asterisk uses one safe generic endpoint payload. An unknown phone format uses generic XML and does not by itself block installation. SIP and SIPS URIs retain UDP, TCP, TLS, ports, parameters, and IPv6 syntax without forcing a transport. “Queued” or “submitted” means Asterisk accepted the request; it does not prove that every handset answered, displayed the message, or acknowledged a SIP NOTIFY. Review Asterisk and phone logs and test each target device.

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

Log retention is configured in `Mass Notify > General Settings`; default retention is 90 days, maximum is 365 days. Notification Logs classifies records by origin/type—Dashboard, Control API, Scheduling, Weather, Lightning, manual test, desktop, system/error, or other—without rewriting historic JSONL data. The page can combine notification-type, PBX-local calendar-date, and row-limit filters, with a one-click filter reset. Weather severity remains available in each event's detail view.

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

NWS appears only for the **Weather Alerts** portion of the module: API details, U.S. weather.gov zone groups, phone/desktop/email recipients, weather TTS voice, and technical logs. Weather Alerts supports United States weather.gov zones only and is locked to the official `https://api.weather.gov` endpoint. The overall product remains Southland Servers Mass Notifications Server.

### How are announcements delivered?

The dashboard announcement widget can send SIP NOTIFY text/image payloads, publish to desktop clients, and independently use no audio, tones only, TTS only, or tones plus TTS. Opening and closing System Recordings can be selected per send. General Settings can also hold up to 10 optional named Dashboard webhook destinations; each enabled webhook appears as an individual target and receives branded Discord embed JSON when selected. A webhook may be the only destination. Local phone, audio, and desktop submission is attempted before bounded external delivery, and the result identifies partial webhook failures without exposing URLs.

### How does Scheduling work?

Open **Mass Notify > Scheduling** and create a one-time schedule with one or more future PBX-local dates and times, or select **Every 7 days** or **Every 14 days** with one starting date and time. A repeating schedule runs at that same PBX-local time and is expanded into a validated, protected occurrence series covering up to five years. Each schedule stores its phone, announcement-group, desktop, audio, voice, volume, tone, and optional Labs color choices in the protected central config. The Asterisk-owned worker checks once per minute. Pre-delivery cooldown, busy, or temporarily offline target conditions can retry inside a 15-minute grace window; a delivery already claimed by an interrupted worker is marked for review instead of being replayed.

Scheduled pages use the PBX operating-system timezone. Nonexistent or ambiguous daylight-saving times are rejected, including unsafe dates within a repeating series, and Dashboard health warns when FreePBX's PHP timezone differs. Scheduling is serialized with ordinary announcements, so items placed closer together than the configured cooldown can run late and remain eligible only inside the protected grace window. To re-arm a failed or missed occurrence, remove that date and add a new future date; an uncertain occurrence must be reviewed and is never replayed automatically.

Portable `.config` exports contain schedule definitions but not the PBX-local execution ledger, so importing one disables its schedules for review. Native FreePBX backups include the protected execution journal and restore it through the replay-safe validation path.

Times that do not exist during a daylight-saving transition, or that occur twice during the fall transition, are rejected so the delivery instant is never ambiguous.

### Can regular announcement screens expire?

Yes. **General Settings > Announcement Timeout** defaults to **No expiry**. It can instead follow the generated page-audio duration or use a fixed 1–86,400 second timeout. Regular Yealink text/image payloads receive the XML timeout, and live desktop events receive timeout and UTC expiry metadata. Other phone families depend on their own firmware behavior; Weather Alert expiry fields are not changed by this setting.

### Is the setup wizard mandatory?

Yes. New installs show a setup modal on the Dashboard announcement widget and Mass Notify pages until the beta warning, AGPL notice, EULA, and first-run configuration are accepted. Existing deployments keep their central `.config` and are not forced through the wizard during an update.

### Do phones need separate API endpoints?

No. Phones receive SIP NOTIFY directly from Asterisk/PJSIP. Desktop clients use `/api/sipnotify/desktop/stream` for live events or `/api/sipnotify/desktop` for JSON fallback with their assigned username and password.

### Can I regenerate credentials?

Yes. The Control API key can be regenerated, and desktop clients can be given new per-client usernames/passwords from the FreePBX UI. Fresh installs generate random Control API, desktop encryption, desktop client, and AMI credentials. Updates preserve credentials in the central `.config` unless an administrator intentionally changes them.

### Can I change the alert email domain?

Yes. Open **Mass Notify > General Settings > Outbound Delivery > Manage Delivery**. The local part and domain are both editable; a fresh install defaults to `no-reply@<postfix-or-pbx-domain>`. For example, changing the domain from `pbx.example.com` to `example.com` produces `no-reply@example.com`. Existing valid sender settings migrate forward, and the canonical values follow protected config backups and validated Control API updates.

This setting changes only the sender address used by Mass Notify alert messages. It does not configure Postfix, an SMTP relay, DNS, SPF, DKIM, DMARC, or PTR/reverse DNS. Configure those separately so the selected domain is authorized to send mail from this PBX.

### Does the Control API allow remote control?

Only if enabled. It is disabled by default and protected by its own API key.

### How do automatic updates work?

Automatic updates are disabled by default. When enabled, a root-owned job checks only the official repository, accepts release assets that include a GitHub SHA-256 digest, downloads the installer from the matching immutable release tag, and supplies the expected digest to the installer. The Asterisk service account cannot replace the updater or Piper executable runtime.

Update availability is checked even when automatic installation is disabled. General Settings and Dashboard health show a yellow warning when a newer accepted release is available. **Update to Latest Release** appears only for a strictly newer version. The updater accepts the project’s normal three-part release tags with an optional `-beta` suffix. Progress moves through queued, checking, installing, and complete states; failures remain visible instead of silently disappearing.

### Are all phone models guaranteed to display SIP NOTIFY payloads?

No. The module implements documented XML families and detects registered-contact User-Agents, but actual behavior depends on model, firmware, provisioning, authentication, and certificate trust. Yealink audio auto-answer uses `Alert-Info: Intercom`; the phone must allow intercom auto-answer. Yealink XML display also requires **Features > Remote Control > SIP Notify** or provisioning value `push_xml.sip_notify = 1`. The installer warns when it finds Yealink contacts but does not change phone firmware, provisioning, or SIP peers. Use **Yealink - Text Only** when a phone cannot retrieve the image, and test every target model before emergency use.

### What happens during quiet hours?

Live Weather Alerts can be suppressed unless the event is configured as critical. Lightning Alerts has its own independent quiet-hours on/off toggle and schedule. Dashboard announcements warn the user if Weather Alert quiet hours are active.

### Can I use my own tones?

Yes. Upload audio through **Admin > System Recordings**, then select it as an opening or closing tone in General Settings, Weather Alerts, Lightning Alerts, Scheduling, or a dashboard announcement. Either tone can be **None** without changing or breaking `extensions_custom.conf`. The installer registers four ownership-safe recordings: **SLS Mass Notify - Paging Tone Opening**, **SLS Mass Notify - Paging Tone Closing**, **SLS Mass Notify - NWS Alert**, and **SLS Mass Notify - Lightning Alert**. It refuses a conflicting user-owned recording instead of overwriting it. Fresh regular announcements use both paging tones, Weather Alerts use the NWS tone with no closing tone, and Lightning uses the Lightning tone with no closing tone; all three profiles default to 25% volume. Tone-only mode pages sounds without speaking the typed text.

Generated TTS and combined announcement WAV files are automatically removed after 15 minutes.

### Can different NWS zones notify different devices and email addresses?

Yes. Configure up to five named NWS zone groups and select phone extensions, enabled desktop clients, optional live-alert email recipients, Discord webhooks, and generic HTTPS webhooks independently for each group. A live zone may use any one of those destination types, including email-only or webhook-only routing. A zone's email list is used only for live alerts from that zone and is limited to 50 unique addresses. If an NWS alert spans overlapping configured zones, the same shared destination is claimed once rather than notified twice. The opt-in system/error recipients in General Settings are separate and do not receive Weather alerts. Manual NWS tests can target all configured groups or selected groups and never send email or webhooks, so a selected test group still needs a phone or enabled desktop target. A manual test attempts each selected local channel independently and reports any partial failure after preserving successful submissions.

### How do Xweather lightning alerts work?

The dedicated **Lightning Alerts** tab uses Xweather `lightning/closest`. Configure up to five named trigger areas. Each area has its own Weather.gov adaptive trigger group, Xweather location and radius, phone extensions, desktop clients, optional live-alert email recipients, quiet hours, all-clear choice, and strike-type filter: cloud-to-ground (the default), cloud-to-cloud, or both. An area's email list and quiet-hours policy apply only to alerts from that area. API credentials, polling cadence, tones, voice, volume, and enabled webhook definitions are shared across the Lightning service. Coordinate locations are spoken naturally as “this area” instead of reading latitude/longitude aloud, while named locations use the configured city. Each combined Lightning page retains one second of leading silence before its pre-tone and speech.

The default-on **Adaptive protection** toggle requires a Weather Alert group for every enabled Lightning area. Xweather remains idle until that area has a qualifying current Weather.gov event or the structured forecast indicates thunder for the current forecast period, then polls through the configured grace period. A future forecast interval is remembered without spending Xweather tokens early; its cache expires at the interval boundary so the first scheduler run at or after the forecast start can open polling. Current alerts remain authoritative, and the shared protected quota governor still limits the whole account. Adaptive protection preserves allowance by trading coverage: an unexpected storm can still arrive before Weather.gov opens the gate. Turning protection off polls every enabled area continuously at the configured 1–10 minute period; 1–4 minute choices display a hazard warning, and periods above 5 minutes can miss strikes because standard Xweather access covers only the recent five-minute window.

The usage panel shows compact provider-period and token cards. A reset time that has already passed is labeled as a historical period and is never presented as current usage. A successful storm query or **Verify Applied Areas** refreshes the snapshot; verification performs one live query for every enabled area. A concise notice explains that multiple active areas use tokens faster.

One warning is sent when a storm first enters the radius. The warning uses Xweather's nearest-strike distance and reports it to one decimal mile (for example, 4.1 miles) rather than reading the configured radius as the strike distance. Additional strikes from that active cluster do not create repeat alerts. Two consecutive clear queries reset the state; an optional all-clear can be sent, and a later storm can then generate a new warning. Credentials remain only in the protected central config and are redacted from diagnostics and Control API responses. The UI links to the official Xweather key setup guide; see the [Xweather Lightning API documentation](https://www.xweather.com/docs/weather-api/endpoints/lightning).

Live Weather.gov and Xweather phone/Desktop dispatch uses a durable at-most-once local intent. If a worker stops after that intent is recorded, automatic recovery does not replay either local channel and reports the outcome as indeterminate; this prevents a likely duplicate at the cost of a possible missed local alert and is not an exactly-once guarantee. Email and webhook tasks are durably queued separately and retried after local handling, so that local crash tradeoff does not discard pending external deliveries.

The Lightning system test can target one or more enabled, applied areas and has its own 60-second anti-spam cooldown. Test phone, audio, and desktop content is explicitly labeled **TEST ONLY** so a validation cannot be mistaken for a real lightning event. Tests do not send email, Discord, or generic webhook notifications. The saved Client Secret is masked in Lightning Alerts and can be revealed by an authenticated FreePBX administrator with the eye button; diagnostics and APIs never return it.

Settings use FreePBX’s standard top-right **Apply Config** control. Saving a module form stages the protected central configuration and marks FreePBX for reload; the native config hook atomically applies it. Install and repair rebuild FreePBX Dashboard's stored hook index and verify that the announcement panel renders. A root maintenance check restores the managed Dashboard widget and menu placement after Dashboard or Framework replacement and corrects the central config to `0640 asterisk:asterisk` without rewriting its contents. Install, update, repair, and uninstall share the same root-owned maintenance lock so the minute worker cannot rewrite integration files during a deployment transaction; signer and verifier children do not inherit that descriptor. The menu repair supports both the numeric comparator used by earlier Framework 17 builds and the boolean comparator introduced by Framework 17.0.30.

### What do alert emails look like?

General Settings manages the local Postfix sender identity, reusable Discord and generic HTTPS alert destinations, a separate set of up to 10 Dashboard announcement webhooks, and an optional list for module system/error notices. Each Weather zone selects its own email and alert-webhook destinations; each Lightning area selects its own email recipients while using the enabled shared alert-webhook definitions. A Dashboard destination can be a standard Discord webhook or another public HTTPS receiver that accepts Discord-compatible embed JSON, and is contacted only when explicitly selected for a real announcement. Email is handed to the local Postfix/sendmail path as a branded multipart message with a plain-text alternative. Every Discord card, including a selected Dashboard announcement webhook, uses the SLS Mass Notification System display name and stable public Southland Servers HTTPS logo as its profile image and compact author/footer icon; card artwork remains a thumbnail. Generic alert webhooks receive bounded structured JSON with an event ID and idempotency header. URLs must pass HTTPS, DNS, public-address, size, and redirect checks; stored secrets remain redacted. Manual Weather and Lightning tests never contact these external destinations.

### Where do I report bugs?

Use GitHub Issues:

```text
https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
```

## Support

Project information: https://southlandservers.xyz/projects

Discord: https://southlandservers.xyz/discord

Issues: https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues
