# Security Policy

Southland Servers Mass Notifications Server is a beta FreePBX module that can send PBX alerts, desktop notifications, SIP NOTIFY messages, and optional TTS audio pages. Treat it like infrastructure software: test changes on a non-critical PBX first, restrict administrative access, and keep FreePBX, Asterisk, Debian packages, and this module updated.

## Supported Versions

Security fixes are currently targeted at the latest beta release candidate only.

| Version | Supported |
| --- | --- |
| `0.0.7-beta` | Yes |
| `0.0.6-beta` | No |
| `0.0.5-beta` | No |
| `0.0.4-beta` | No |
| `0.0.3-beta` | No |
| `0.0.2-beta` | No |
| Older beta builds | No |

## Reporting

Report security issues privately through Southland Servers project channels when possible. If the concern is not sensitive, open an issue:

https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues

Project and community links:

- https://southlandservers.xyz/projects
- https://southlandservers.xyz/discord

Please include the module version, FreePBX version, Asterisk version, relevant logs, reproduction steps, and whether the issue affects authentication, authorization, file writes, command execution, SIP NOTIFY delivery, TTS generation, or external API access.

Do not post live API keys, desktop client passwords, AMI credentials, bearer tokens, `.config` files, or production logs containing sensitive alert content in public issues.

## Secrets

API keys, encrypted desktop client passwords, AMI credentials, Xweather client secrets, notification groups, and deployment settings are stored in the central Mass Notifications config and should not be committed to Git.

Do not publish:

- `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`
- production logs
- generated TTS audio

Credentials are generated on fresh installs and preserved during normal updates. If a credential is regenerated from the UI, update every client or endpoint that uses it.

## Recommended Deployment Controls

- Use HTTPS for all API and media endpoints.
- Keep the Control API disabled unless it is actively needed.
- If the Control API is enabled, consider enabling its IP allowlist and per-IP rate limit.
- Restrict FreePBX administrator access to trusted users and trusted networks.
- Use strong desktop client passwords and keep the Control API disabled unless it is needed.
- Rotate desktop client credentials and the Control API key if they are exposed.
- Keep AMI access bound to localhost unless a deployment has a specific, reviewed need.
- Do not expose Asterisk manager ports directly to the public internet.
- Review notification logs regularly and configure retention according to local policy.
- Validate uploaded tones and images through the module UI instead of placing arbitrary files in runtime directories.
- Back up the central `.config` file securely; it contains operational settings and credentials.

## Security Boundaries

The desktop notification API and Control API are intended for authenticated clients only. Desktop app clients use per-client usernames and passwords over HTTPS. The primary transport is the live server-sent-event handshake; the JSON endpoint remains a fallback. Both filter event records by explicit desktop routing fields, and legacy untargeted records are denied. The Control API is disabled by default, uses constant-time key comparison, supports optional IPv4/IPv6 allowlisting and rate limiting, records a bounded audit trail, limits JSON request size, and never returns stored secrets in config responses.

FreePBX UI mutations use a module CSRF token. Uploaded tones are size-limited and decoded/re-encoded by SoX; imported config files are size-limited and schema-validated before staging. Weather, Xweather, and announcement text is passed to subprocesses as argument arrays or shell-escaped values, and ImageMagick text metacharacters are neutralized before rendering. Xweather request fields are URL-encoded, TLS verification and bounded retries are enabled, and the client ID/secret are stored only in the protected `mass-notifications.config` file and are neither logged nor returned by the Control API.

Default-on adaptive Lightning protection reads credential-free, short-lived Weather.gov gate files scoped to the administrator-selected zone group. Gate files older than three minutes are ignored. Its persisted quota bucket limits scheduled Xweather queries to the configured account-period allowance, while manual connection tests remain explicit extra queries. Disabling the toggle intentionally permits continuous Xweather polling regardless of NWS conditions. Adaptive mode reduces API use but can miss lightning outside a qualifying Weather.gov event and is not a substitute for a dedicated safety-grade lightning network.

Public PBX Hostname is automatically detected and exposed read-only in administrator forms; it is not accepted as a Control API configuration mutation. Successful loopback `get_config` and `get_status` health probes are omitted from the API usage audit, while authentication failures, non-loopback requests, and meaningful local actions continue through the normal audit controls.

Settings participate in FreePBX’s native Apply Config hook and remain staged in a protected Asterisk-owned file until reload. The root maintenance worker compares only the managed Dashboard widget and menu integration files after FreePBX updates; when drift is detected it restores those known files from the installed module and refreshes local signatures. It does not modify phone provisioning, PJSIP peers, or unrelated FreePBX module content.

Executable runtime under `/usr/local/bin/sls_mass_notify`, including Piper, maintenance, and updater code, is owned by `root:root`. Mutable deployment data remains under the Asterisk data folder. The root updater only accepts the official beta repository, requires GitHub release SHA-256 metadata, and executes the installer from the matching immutable release tag. Automatic updates remain disabled by default.

Phone SIP NOTIFY delivery is sent directly by Asterisk/PJSIP to registered endpoints. Vendor XML support is model-, firmware-, provisioning-, authentication-, and certificate-dependent; do not interpret a successful AMI send as proof that a phone displayed the payload.

The module does not replace FreePBX system hardening. Firewall rules, TLS certificates, fail2ban policies, OS patching, mail transport security, and SIP trunk security remain the responsibility of the PBX administrator.

## Dependency Security

The installer uses Debian packages, creates a dedicated Piper virtual environment with pinned packaging tools and `piper-tts`, and downloads Piper voice models from a pinned repository revision with exact SHA-256 verification. Release TGZ paths and metadata are validated before extraction. Use a trusted network for installation, verify release checksums, and run installers only from the official project source.

The project locally signs its custom module and the FreePBX modules containing managed integration files. The signing key is generated on each PBX and trusted only in that PBX's FreePBX GPG home. This detects later local file alteration but is not a publisher-distributed release signature or a substitute for verifying the release download.

## Disclosure Target

For beta releases, the goal is to acknowledge valid security reports quickly and publish fixes in the next beta package when practical. Severe issues affecting authentication, arbitrary file writes, command execution, or unauthenticated alert sending should be treated as urgent.
