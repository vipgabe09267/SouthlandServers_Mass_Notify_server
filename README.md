<p align="center">
  <a href="https://southlandservers.xyz"><img src="https://southlandservers.xyz/images/SLS_Mass_Notif_Plugin.png" width="112" alt="Southland Servers"></a>
</p>

# Southland Servers Mass Notifications Server

Phone, desktop, weather, lightning, and scheduled notifications for FreePBX 17 / Debian 12. AGPL-3.0-or-later. Version `0.1.2-beta`.

## Install or update

Run as `root` on the PBX:

```bash
cd /tmp
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.2-beta/tools/install_release.sh
chmod +x sls-install.sh
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.2-beta/slsmassnotifyserver-0.1.2-beta.tgz' \
./sls-install.sh
```

Existing configuration and credentials are preserved. Fresh installations open a setup wizard in FreePBX. Check the displayed system timezone, complete setup under **Mass Notify**, and use FreePBX’s **Apply Config** button when it appears.

This is beta software, not a replacement for certified emergency-alert equipment. Test your phones, desktop clients, and external destinations before relying on them. The installer verifies supported PBX capabilities; it cannot enable a handset’s auto-answer or XML push policy.

## Using Mass Notify

- **Dashboard:** send text, colored phone alerts, tones, or Piper speech to selected phones, groups, desktops, and optional announcement webhooks. A compact footer shows progress, a green check with the sender after successful submission, and expandable channel results. Choose Urgent to move ready audio ahead of waiting normal pages; active playback and cooldown remain protected.
- **Weather Alerts:** monitor up to five U.S. Weather.gov zones. Each zone selects its own phones, desktops, email recipients, webhooks, and quiet hours. New observations continue while queued alerts are delivered chronologically; expiry, cancellation, and overlapping-zone deduplication are checked before submission.
- **Lightning Alerts:** configure up to five areas with their own location, radius, strike type, destinations, quiet hours, and all-clear rules. Adaptive polling follows current Weather.gov alerts and the forecast period active now. Query cadence, provider token allowance, and fresh-observation checks still apply.
- **Scheduling:** choose specific dates or repeat every 7 or 14 days at a PBX-local time. Recurrence is a finite series of up to five years; review the displayed last occurrence. It does not renew indefinitely.
- **General Settings:** manage clients, tones, voices, volumes, email identity, webhooks, Control API access, and phone formats. Download redacted diagnostics for support. Saved Channel Checks are optional reusable phone/desktop tests, explained by the question-mark beside their heading.
- **Notification Logs and Help:** review notification type, date, sender, destination outcomes, connection status, and diagnostic checks.

Fresh defaults are Lessac for announcements, Amy for Weather and Lightning, and 25% volume for all three. Announcements include the opening and closing paging tones; Weather uses its NWS opening tone, Lightning its own opening tone, and neither has a default closing tone. Tones can be set to None. Generated audio includes one second of leading silence and is retained for fifteen minutes after reserved playback ends.

The paging answer window defaults to five seconds and can be shortened to one through five seconds in General Settings. It is separate from visual-message expiry, which defaults to no expiry. Longer ringing is not a substitute for configuring phone auto-answer.

Saved channel checks support up to ten named profiles with specific phones/desktops and audio-only, visual-only, or combined delivery. They use announcement settings, respect cooldown, and do not send email/webhooks or query Xweather. Save and apply a profile before running it.

To find a Weather.gov zone, open the [NWS Public Zone Maps](https://www.weather.gov/pimar/PubZone), choose your state, and find your area’s three-digit zone number. Combine the state abbreviation, `Z`, and that number: Texas zone 163 is `TXZ163`.

## Delivery and compatibility

“Submitted” means the PBX accepted that channel’s work—not that a person heard it, a handset displayed it, or an email arrived. Failed channels do not suppress independent destinations. Retry is available only for confirmed failed announcement destinations; interrupted or uncertain submissions are not automatically replayed.

Desktop clients use authenticated live SSE at `/api/sipnotify/desktop/stream`. The JSON endpoint remains a compatibility fallback. Reconnecting clients receive eligible retained events, and compatible clients can acknowledge events through the optional ACK endpoint. Connection presence, publication, app acknowledgment, and human receipt are different states. The module cannot make a sleeping app receive immediately.

PJSIP UDP, TCP, and TLS contact syntax is preserved. Vendor payloads are selected per registration where Asterisk supports safe contact routing; otherwise a generic XML endpoint fallback is used. Unknown devices do not block installation, but may ignore generic XML. See [phone compatibility](PHONE_FORMATS.md) for provisioning requirements and limits.

## Configuration, backup, and updates

All portable user settings and revocable desktop credentials remain in:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

The historical directory name is retained for upgrades. Protect this file and its backups: it contains secrets, including recoverable encrypted desktop credentials. Execution journals and delivery state are separate runtime data, not configuration.

Use **General Settings > Config Backup > Download .config** and a module-based FreePBX backup job. The native adapter includes configuration, custom tones, and schedule execution history. On a replacement PBX, install the module before restoring its backup. Portable config imports disable schedules for review because they do not carry execution history.

Manual and opt-in automatic updates verify a publisher-signed manifest covering the installer and TGZ. The updater resolves the release to a commit and installs the exact verified archive. New fixes require a new version; same-version replacements do not trigger updates. Older unsigned releases require their own tagged installer. See [installation and recovery](INSTALL.md) for offline installs, prerequisites, repair, and rollback.

Locally signed custom modules may display **Unknown** in FreePBX. Verification must still return trusted status `129`. Dashboard integration is checked and repaired after FreePBX updates; review health after any upgrade or restore.

## Uninstall

Normal uninstall preserves the central configuration, backups, uploaded tones, and schedule execution history:

```bash
cd /tmp
curl -fsSL -o sls-uninstall.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.2-beta/tools/uninstall_release.sh
chmod +x sls-uninstall.sh
./sls-uninstall.sh
```

A complete purge is destructive and requires explicit confirmation. See [INSTALL.md](INSTALL.md) before removing a live installation.

## Documentation and development

[Installation and recovery](INSTALL.md) · [Phone formats](PHONE_FORMATS.md) · [Changelog](CHANGELOG.md) · [Security](SECURITY.md) · [Release notes](RELEASE_NOTES_0.1.2-beta.md)

Run `./tools/build_tgz.sh` from the repository root to run the release checks and produce `dist/slsmassnotifyserver-0.1.2-beta.tgz`. Signing requires the publisher’s private Ed25519 key outside the repository; `SLS_RELEASE_SIGNING_KEY` selects it. Publish the archive, `release-manifest.json`, and `release-manifest.sig` together. No configuration, credentials, voice models, generated media, logs, or private signing keys belong in the package.

[Southland Servers](https://southlandservers.xyz) · [Discord](https://southlandservers.xyz/discord) · [Report an issue](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues)
