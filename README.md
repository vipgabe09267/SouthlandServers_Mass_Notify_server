<p align="center">
  <a href="https://southlandservers.xyz"><img src="https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/main/slsmassnotifyserver/assets/SLS_Mass_Notif_Email.png" width="112" alt="Southland Servers"></a>
</p>

# SLS Mass Notifications Server

Phone, desktop, weather, lightning and scheduled notifications for FreePBX 17 on Debian 12. `0.1.7-beta` prerelease candidate · AGPL-3.0-or-later. Publication is pending.

> **Beta software for noncritical testing. Do not rely on SLS for critical or urgent notifications.** Each FreePBX administrator accepts the warning once; their acknowledgment survives upgrades. Confirmation requires five visible seconds.

[Install/update](#install-or-update) · [Uninstall](#uninstall) · [Service commands](#service-commands) · [Repair/recovery](#repair-and-recovery) · [Requirements](#requirements) · [Documentation](#documentation)

## Install or update

The candidate is not published yet. Once released, run as **root** on the PBX.
For a first installation, use [publisher verification](docs/FIRST_INSTALL_VERIFICATION.md)
before executing downloaded code. If you already have an independently verified
installer hash, the short workflow is:

```bash
SLS_INSTALL_DIR="$(mktemp -d /tmp/sls-verified-install.XXXXXXXX)"
cd "$SLS_INSTALL_DIR"
curl -fsSL -o sls-install.sh \
  https://raw.githubusercontent.com/vipgabe09267/SouthlandServers_Mass_Notify_server/slsmassnotifyserver-0.1.7-beta/tools/install_release.sh
SLS_INSTALLER_SHA256='REPLACE_WITH_THE_INDEPENDENTLY_VERIFIED_INSTALLER_SHA256'
printf '%s  sls-install.sh\n' "$SLS_INSTALLER_SHA256" | sha256sum --check || exit 1
SLS_MASS_NOTIFY_TGZ_URL='https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.7-beta/slsmassnotifyserver-0.1.7-beta.tgz' \
bash ./sls-install.sh
```

This command installs or upgrades using the publisher-signed package. Each release version identifies one signed package. Existing settings and credentials are preserved.

Open **Mass Notify** in FreePBX, complete the setup wizard, review the timezone and use **Apply Config** when prompted. Forwarded ports are optional installation settings; shared defaults remain HTTPS 443 and HTTP 80. See [installation requirements, offline packages and supported layouts](INSTALL.md).

## Uninstall

The installed uninstaller works **offline**, with no download or reinstallation:

```bash
sudo slsconsole uninstall
```

Direct command for installations that include the removal script but lack `slsconsole`:

```bash
sudo /usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh
```

Normal removal preserves configuration, backups, uploaded tones, schedule/delivery history and logs. It drains SLS workers and refuses active phone delivery. Missing unrelated runtime helpers do not prevent removal; changed security evidence requires review. Complete purge is destructive and requires confirmation. See [removal and purge](INSTALL.md#uninstall) and [recovery-key retention](docs/RECOVERY.md).

## Service commands

| Command | Purpose |
| --- | --- |
| `sudo slsconsole status` | Show notification admission and collector state; add `--json` for scripts. |
| `sudo slsconsole stop` | Pause new SLS notifications; existing calls and receipts continue. |
| `sudo slsconsole start` | Start the collector if needed and resume SLS notifications. |
| `sudo slsconsole reboot` | Restart the SLS collector and retain the prior running/stopped state. |
| `sudo slsconsole doctor` | Inspect installation health without repair or notification delivery; add `--json` for scripts. |
| `slsconsole help` | Show command usage. |

These commands preserve other active and pending settings. They do not reboot the host or restart Asterisk, Apache or the FreePBX firewall. A failed collector restart leaves SLS admission paused.

## Repair and recovery

Use **General Settings > Danger Zone > Repair Installation**, or rerun the signed installation command above. Repairs preserve settings and delivery history. Unknown executable changes, unsafe files and unsupported layouts require review rather than bypassing verification.

To inspect before installation, use the downloaded matching installer:

```bash
bash ./sls-install.sh --inspect \
  'https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/releases/download/slsmassnotifyserver-0.1.7-beta/slsmassnotifyserver-0.1.7-beta.tgz'
```

If an attempt reports an unfinished transaction, preserve its directory and substitute the **exact path printed in the installer log**:

```bash
bash ./sls-install.sh --recover '/var/lib/sls-mass-notify-installer/transaction-<id>'
```

Then rerun the verified installer. Do not delete recovery evidence or change live hashes to force approval. Keep `/tmp/slsmassnotifyserver-install.log`; see [troubleshooting](INSTALL.md#installer-log-and-runtime-errors), [legacy permission recovery](docs/INSTALLER_OPERATIONS.md#recognized-legacy-permission-recovery) and [interrupted installation recovery](docs/INSTALLER_OPERATIONS.md#interrupted-installations).

## Requirements

The supported installer profile is **FreePBX 17 / Debian 12 / amd64**, with PJSIP and standard FreePBX paths. Prerequisites and supported configuration variants are listed in [INSTALL.md](INSTALL.md). The installer checks capabilities, installs missing supported dependencies and preserves administrator-controlled listeners, trunks, firewall and handset settings.

- **Default capacity:** 25 desktops and 25 phone contacts; combined minimum **2 effective CPU cores and 4 GiB RAM**. Allow 4 cores / 8 GiB for ordinary PBX traffic, recording or more synthesis.
- **Scaling:** desktop capacity supports up to 1,000 identities. Phone capacity counts contacts, including multiple handsets on an extension. Fresh phone inventories above 25 round up to the next 50; upgrades preserve saved limits.
- **Storage:** checks require additional free SLS workspace on the affected filesystems, including temporary and recovery space. There is no fixed whole-volume minimum. PBX recordings, voicemail and backups need separate space.
- **Admission:** insufficient resources reject capacity increases. Oversized audiences are rejected; occupied phone capacity allows a bounded wait. Recipients are never silently dropped to fit a limit.

**General Settings > Phones and address** previews CPU, RAM and workspace requirements with green checks or red X indicators. These are engineering allocations, not certified fleet throughput. See [capacity tables, storage calculations and retention](docs/RESOURCES.md).

## Features

| Page | What it provides |
| --- | --- |
| Dashboard | Selected phone/desktop/email/SMS/webhook destinations, tones or speech, colored messages, previews and per-destination results. |
| Weather Alerts | Up to five U.S. Weather.gov zones with separate audiences, quiet hours, detailed email and webhook delivery. |
| Lightning Alerts | Up to five areas, provider/radius/strike settings, adaptive polling, quota controls and all-clear rules. |
| Scheduling | Dated or repeating announcements, weekdays, A/B calendars, holidays and reviewed CSV/iCalendar imports. |
| Dial-in Paging · Labs | One extension, up to ten groups, live audio, saved SIP text, authorized callers and individual 4–8-digit PINs. |
| Locations and Audiences · Labs | Sites/buildings/floors/rooms, individual destinations and reviewed groups combining a person's devices or shared recipients. |
| Operator Access / Portal · Labs | Scoped logins with passwords, mandatory TOTP, optional expiry, recovery and audited announcements. |
| Incidents and Drills · Labs | Templates, separate updates/all-clear, reviewed language variants, responses, checklists and reports. |
| Triggers and Actions · Labs | Approved panic/sensor/CAP inputs and configured device, webhook or script actions. |
| General Settings / Help | Channel configuration, voices, capacity, backups, updates, diagnostics and deployment readiness. |

Optional channels and Labs features start disabled. Phone/SMS contacts share one recipient editor with independent Call/SMS choices. Reviewed groups and submitted announcements keep their recipients fixed; later directory changes cannot add recipients to old work.

## Delivery and compatibility

- **Phones:** audio and visual delivery require appropriate handset provisioning. Multiple registrations are handled per contact where safe routing is supported. If HTTPS does not work, try HTTP for legacy phones. See [phone formats](PHONE_FORMATS.md).
- **Voicemail:** actual Weather, Lightning and recorded live pages can leave one message per unanswered internal extension with an eligible FreePBX mailbox. Independent controls start enabled. Ordinary Dashboard TTS, manual alert tests and external-number calls are unchanged.
- **Weather:** speech retains the alert's official instructions. Invalid enabled quiet hours suppress noncritical delivery; missing, unsafe or full deduplication history blocks new alerts and preserves records for review.
- **Alert settings:** new Weather groups include site, SMS and external-call selections. Imported Weather recordings remain selected on reopening. Lightning preserves the chosen polling period and offers the selected provider’s supported range.
- **Desktops:** the [separate desktop app](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_app) receives live alerts. It skips messages missed while disconnected and applies a ten-minute maximum lifetime. `Received by desktop app` confirms software receipt, not human reading. Schema 1 and SSE protocol 2 remain compatible. PHP-FPM uses cursor-based polling so desktop connections do not hold shared PBX workers.
- **Email:** disabled on fresh installs. Configure local Postfix and recipients, then enable announcement email under **General Settings > Delivery**. Queue backpressure and shared quotas prevent unlimited SLS mail submission; mail-service acceptance does not confirm inbox delivery.
- **SMS/MMS · Labs:** Twilio, Telnyx and BulkVS adapters require provider setup and consented recipients. BulkVS currently reports API acceptance; authenticated inbound delivery and STOP callbacks are unsupported. See [SMS setup and limits](docs/SMS.md).
- **Webhooks:** native JSON, Discord, Slack and Teams Workflows. Accepted or unconfirmed one-way delivery does not require a human receipt; explicit HTTP failures still need attention.
- **External voice · Labs:** opt-in FreePBX routing, independent answer-gated playback, optional keypad acknowledgment and a daily call allowance. Supported route profiles are restricted; carrier-greeting detection is not implemented.

Phone answer, conference entry and service acceptance do not prove full playback or human receipt. Interrupted or uncertain submissions are not automatically replayed. A collector fault blocks new SLS phone audio but does not prevent ordinary FreePBX reloads. See [delivery behavior, voicemail, defaults and troubleshooting](docs/DELIVERY.md).

## Configuration and backups

All portable user settings remain in:

```text
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config
```

Active, pending and retained settings use **AES-256-GCM**. The protected keyring is `/etc/sls-mass-notify/config-keys.json`; maintenance rotates the active key every 365 days and retains recovery keys for older backups. Missing keys or damaged ciphertext block access. Runtime journals and signing authority are separate operational data. See [configuration security](docs/CONFIGURATION_SECURITY.md).

**General Settings > Config Backup** exports a portable encrypted configuration using a separate passphrase of at least 12 characters. It excludes recordings and delivery history. Keep the passphrase separately; there is no recovery for a lost export passphrase. The UI reminds admins to export after 90 days. Plain legacy exports contain reusable credentials.

Native FreePBX module backups include configuration, recovery keys, custom tones and bounded operational evidence. **Protect or encrypt the whole archive.** Install SLS before restoring its module backup; restored automation requires review and old work is not replayed. See [backup and recovery](docs/RECOVERY.md).

## Operator single sign-on · Labs

Configure SAML or OIDC providers under **Operator Access** in the FreePBX admin panel. SSO signs users into `/mass-notify/` only. Saved operator permissions, account expiry and local authenticator verification still apply. Account permissions and SSO settings apply when saved; no FreePBX Apply Config is required. FreePBX and UCP sign-in are unchanged. Providers start disabled and require deployment testing; see [operator SSO and directory setup](docs/ENTERPRISE_IDENTITY.md).

## Enterprise Labs

All integrations require explicit configuration and deployment testing. Failover/mirroring, enterprise login, door actuation and public-warning origination require the danger acknowledgment and are limited to non-production labs. Acknowledgment does not enable a feature.

[Continuity and remote sites](docs/ENTERPRISE_CLUSTER.md) · [Identity and subscribers](docs/ENTERPRISE_IDENTITY.md) · [Devices and providers](docs/ENTERPRISE_INTEGRATIONS.md) · [Incident coordination](docs/ENTERPRISE_OPERATIONS.md)

Offline delivery still needs a working local PBX, network and power. Cloud channels and fresh weather observations require internet access. Notification failover is separate from full PBX/telephone failover. Native mobile push and visitor enrollment are not available.

## Documentation

| Guide | Contents |
| --- | --- |
| [Installation and recovery](INSTALL.md) / [Installer operations](docs/INSTALLER_OPERATIONS.md) | Prerequisites, signatures, offline packages, repair, recovery and removal. |
| [Resources](docs/RESOURCES.md) / [Delivery](docs/DELIVERY.md) | Capacity, storage, defaults, channel evidence and troubleshooting. |
| [Paging](docs/PAGING.md) / [Locations](docs/LOCATIONS.md) / [Scheduling](docs/SCHEDULING.md) | Caller access, recipients, calendars and deadlines. |
| [Operators](docs/OPERATORS.md) / [Incidents](docs/INCIDENT_API.md) / [Triggers](docs/TRIGGERS.md) | Permissions, recovery, workflows and input contracts. |
| [Control API](docs/CONTROL_API.md) / [Desktop readiness](docs/DESKTOP_READINESS.md) | Authentication, scoped credentials, integration examples and client contracts. |
| [Deployment readiness](docs/DEPLOYMENT_READINESS.md) / [Media access](docs/MEDIA_ACCESS.md) | Current health, external monitoring, device acceptance and forwarded addresses. |
| [Updates](docs/UPDATE_POLICY.md) / [Release trust](docs/RELEASE_TRUST.md) | Release policy, publisher signatures and recovery. |
| [Changelog](CHANGELOG.md) / [Security policy](SECURITY.md) | Release changes, known limits and vulnerability reporting. |

For development, see [validation](docs/VALIDATION.md), [qualification boundaries](docs/QUALIFICATION_MATRIX.md) and the [dependency inventory](docs/DEPENDENCIES.md). On a PBX, run checks through `bash tools/run_isolated_tests.sh --build` in private namespaces. Release signing uses `python3 tools/sign_release.py` with the publisher key stored outside the repository; publish the TGZ and both signed-manifest assets together.

[Southland Servers](https://southlandservers.xyz) · [Discord](https://southlandservers.xyz/discord) · [Report an issue](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues)
