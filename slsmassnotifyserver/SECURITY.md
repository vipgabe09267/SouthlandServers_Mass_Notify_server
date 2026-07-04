# Security Policy

Southland Servers Mass Notifications Server is a beta FreePBX module that can send PBX alerts, desktop notifications, SIP NOTIFY messages, and optional TTS audio pages. Treat it like infrastructure software: test changes on a non-critical PBX first, restrict administrative access, and keep FreePBX, Asterisk, Debian packages, and this module updated.

## Supported Versions

Security fixes are currently targeted at the latest public beta release only.

| Version | Supported |
| --- | --- |
| `0.0.3-beta` | Yes |
| `0.0.2-beta` | No |
| Older beta builds | No |

## Reporting

Report security issues privately through Southland Servers project channels when possible. If the concern is not sensitive, open an issue:

https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues

Project and community links:

- https://southlandservers.xyz/projects
- https://southlandservers.xyz/discord

Please include the module version, FreePBX version, Asterisk version, relevant logs, reproduction steps, and whether the issue affects authentication, authorization, file writes, command execution, SIP NOTIFY delivery, TTS generation, or external API access.

Do not post live API keys, SIP endpoint passwords, AMI credentials, bearer tokens, `.config` files, or production logs containing sensitive alert content in public issues.

## Secrets

API keys, endpoint passwords, AMI credentials, notification groups, and deployment settings are stored in the central Mass Notifications config and should not be committed to Git.

Do not publish:

- `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`
- generated runtime `config.ini`
- production logs
- generated TTS audio

Credentials are generated on fresh installs and preserved during normal updates. If a credential is regenerated from the UI, update every client or endpoint that uses it.

## Recommended Deployment Controls

- Use HTTPS for all API and media endpoints.
- Keep the Control API disabled unless it is actively needed.
- Restrict FreePBX administrator access to trusted users and trusted networks.
- Use strong endpoint passwords for SIP NOTIFY phone-brand endpoints.
- Rotate the desktop app token and Control API key if they are exposed.
- Keep AMI access bound to localhost unless a deployment has a specific, reviewed need.
- Do not expose Asterisk manager ports directly to the public internet.
- Review notification logs regularly and configure retention according to local policy.
- Validate uploaded tones and images through the module UI instead of placing arbitrary files in runtime directories.
- Back up the central `.config` file securely; it contains operational settings and credentials.

## Security Boundaries

The SIP Notify API and Control API are intended for authenticated clients only. The desktop app endpoint uses bearer-token authentication. Phone-brand endpoints use the configured endpoint authentication model. The Control API is disabled by default and must be explicitly enabled before use.

The module does not replace FreePBX system hardening. Firewall rules, TLS certificates, fail2ban policies, OS patching, mail transport security, and SIP trunk security remain the responsibility of the PBX administrator.

## Dependency Security

The installer uses Debian packages and downloads Piper TTS voice models during installation. Use a trusted network for installation, verify release checksums, and rerun the installer or repair script only from the official project source.

## Disclosure Target

For beta releases, the goal is to acknowledge valid security reports quickly and publish fixes in the next beta package when practical. Severe issues affecting authentication, arbitrary file writes, command execution, or unauthenticated alert sending should be treated as urgent.
