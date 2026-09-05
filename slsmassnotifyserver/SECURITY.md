# Security Policy

Southland Servers Mass Notifications Server is beta-stage FreePBX software that can send PBX alerts, desktop notifications, SIP NOTIFY messages, webhooks, and optional TTS audio pages. Treat it like infrastructure software: test changes on a non-critical PBX first, restrict administrative access, and keep FreePBX, Asterisk, Debian packages, and this module updated.

## Redacted support downloads

General Settings can download a small JSON diagnostic report through an authenticated FreePBX POST with a valid CSRF token. The response is an attachment with `private, no-store` caching and a 128 KiB limit; no report is stored on the PBX. Exported fields are explicitly allowlisted. Configuration, credentials, hostnames, addresses, device identifiers, message contents, raw logs, and diagnostic detail strings are never copied into it. Versions, permission modes, health booleans, and anonymous device/queue counts remain visible, so share even this report only with intended support recipients. A `.config` backup is a separate, sensitive download.

Urgent priority affects only prepared announcement audio waiting for shared recipients. It does not grant additional destination access, bypass authentication or cooldown, or interrupt active audio. Dead waiting tickets expire; unsafe queue paths and malformed state fail closed.

## Supported Versions

Security fixes are currently targeted at the latest release only.

Version `0.1.2-beta` pins the private Piper environment to pip `26.2.0`, addressing [CVE-2026-13346](https://osv.dev/vulnerability/GHSA-qwm4-qh6w-59xr). Dependency checks are point-in-time checks, not a guarantee that the PBX has no vulnerabilities.

| Version | Supported |
| --- | --- |
| `0.1.2-beta` | Yes |
| `0.1.1-beta` | Upgrade recommended |
| `0.0.9-beta` | No |
| `0.0.8-beta` | No |
| `0.0.7-beta` | No |
| `0.0.6-beta` | No |
| `0.0.5-beta` | No |
| `0.0.4-beta` | No |
| `0.0.3-beta` | No |
| `0.0.2-beta` | No |
| Older beta builds | No |

## Portable desktop credentials

Desktop credentials remain encrypted, recoverable, and revocable in the protected central configuration, as an explicit product choice. The encryption key travels with that configuration for migration. Someone who obtains the complete file can therefore recover these credentials. Protect configuration backups accordingly. Desktop credentials authorize receipt of that client's targeted notifications and optional acknowledgements, not administrative Control API actions.

## Generic webhook authentication (0.1.2-beta)

Generic HTTPS destinations may configure `bearer_token`, `signing_secret`, or both. A signature is HMAC-SHA256 over `timestamp + "." + event_id + "." + raw_request_body`. Headers are `X-SLS-Timestamp`, `X-SLS-Event-ID`, and `X-SLS-Signature: sha256=<hex>`. Receivers should verify signatures in constant time, reject stale timestamps, and deduplicate event IDs. These headers authenticate origin; TLS still protects transport. Discord uses its webhook URL token instead.

API configuration responses redact destination URLs and authentication secrets. Blank fields preserve stored secrets; the explicit remove controls clear them. Release verification also checks the publisher-signed manifest described below.

## Reporting

Report security issues privately through Southland Servers project channels when possible. If the concern is not sensitive, open an issue:

https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues

Project and community links:

- https://southlandservers.xyz/projects
- https://southlandservers.xyz/discord

Please include the module version, FreePBX version, Asterisk version, relevant logs, reproduction steps, and whether the issue affects authentication, authorization, file writes, command execution, SIP NOTIFY delivery, TTS generation, or external API access.

Do not post live API keys, desktop client passwords, AMI credentials, bearer tokens, `.config` files, or production logs containing sensitive alert content in public issues.

## Secrets

API keys, encrypted desktop client passwords, AMI credentials, Xweather client secrets, webhook URLs, notification groups, and deployment settings are stored in the central Mass Notifications config and should not be committed to Git.

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
- Authorize the configured alert sender domain in the site's mail relay and DNS policy, and monitor delivery failures rather than assuming a locally accepted message reached its recipient.
- Use only trusted HTTPS webhook services. Generic webhook hosts must resolve exclusively to public addresses; private, loopback, link-local, and redirect targets are rejected.

## Security Boundaries

The desktop notification API and Control API are intended for authenticated clients only. Desktop app clients use per-client usernames and passwords over HTTPS. The primary transport is the live server-sent-event handshake; the JSON endpoint remains a fallback. Both filter event records by explicit desktop routing fields, and legacy untargeted records are denied. An expired authorized event advances the SSE cursor without being emitted, preventing it from hiding the next valid targeted record. The Control API is disabled by default, uses constant-time key comparison, supports optional IPv4/IPv6 allowlisting and rate limiting, records a bounded audit trail, limits JSON request size, and never returns stored secrets in config responses.

FreePBX UI mutations use a module CSRF token. Uploaded tones are size-limited and decoded/re-encoded by SoX; imported config files are size-limited and schema-validated before staging. Weather, Xweather, and announcement text is passed to subprocesses as argument arrays or shell-escaped values, and ImageMagick text metacharacters are neutralized before rendering. Xweather request fields are URL-encoded, TLS verification and bounded retries are enabled, and the client ID/secret are stored only in the protected `mass-notifications.config` file and are neither logged nor returned by the Control API.

Default-on adaptive Lightning protection reads credential-free, short-lived Weather.gov alert-gate files scoped to the administrator-selected zone group and the structured Weather.gov forecast for that area's resolved point. Alert-gate files older than three minutes are ignored. Only a qualifying current alert or a thunder forecast period active at the current time opens paid polling; a future period is cached only to refresh the decision at its boundary. Its persisted quota bucket limits scheduled Xweather queries to the configured account-period allowance, while manual connection tests remain explicit extra queries. Disabling the toggle intentionally permits continuous Xweather polling regardless of Weather.gov conditions. Adaptive mode reduces API use but can miss unexpected lightning and is not a substitute for a dedicated safety-grade lightning network.

Public PBX Hostname is automatically detected and exposed read-only in administrator forms; it is not accepted as a Control API configuration mutation. Successful loopback `get_config` and `get_status` health probes are omitted from the API usage audit, while authentication failures, non-loopback requests, and meaningful local actions continue through the normal audit controls.

Alert email uses canonical sender-local-part and domain values stored in protected central config. The local part defaults to `no-reply`; the UI, config import, and Control API validate both fields and reject header controls, schemes, mailbox-in-domain input, IP literals, and malformed DNS labels. Older valid sender settings supply migration values when canonical keys are absent. This setting does not configure or secure Postfix, an SMTP relay, SPF, DKIM, DMARC, PTR/reverse DNS, or any other DNS/mail infrastructure.

Live Weather and Lightning destinations share a bounded dispatcher. Email recipients are scoped to the matching Weather zone or Lightning area; Weather zones also choose specific enabled webhook destinations, while Lightning uses the enabled shared webhook set. Dashboard announcements use a separate protected list of no more than 10 Discord or Discord-compatible HTTPS webhooks and send only to the IDs explicitly selected by an authenticated FreePBX administrator. Discord-hosted URLs must match Discord's HTTPS webhook shape; compatible receivers require a DNS hostname whose resolved addresses are all globally routable. Requests use certificate verification, validated-address pinning, no redirects, limited retries, bounded payloads, and an idempotency key. Dashboard webhook work begins only after requested local phone, audio, and desktop submissions, so external network latency cannot gate those urgent local channels. Destination secrets are omitted from API/config responses, UI markup, logs, and safe result records. Manual tests, previews, dry runs, and direct CLI use cannot send external webhook traffic without the internal live-delivery gate.

The chronological Weather dispatcher coordinates cross-zone delivery through a root-path-constrained, no-follow state file and sidecar lock. The journal stores domain-separated hashes rather than phone numbers, desktop usernames, email addresses, or webhook identifiers, rejects non-regular files and invalid schemas, and applies entry, size, and retention bounds. Claims are made before irreversible local or external submission, so a crash favors suppression of a possible duplicate over automatic replay.

Settings participate in FreePBX’s native Apply Config hook and remain staged in a protected Asterisk-owned file until reload. The root maintenance worker compares only the managed Dashboard widget and menu integration files after FreePBX updates; when drift is detected it restores those known files from the installed module and refreshes local signatures. Install, update, repair, and uninstall operations use the same root-owned maintenance lock, preventing the minute worker from changing managed files during a deployment transaction. A maintenance-launched child reuses the inherited lock rather than opening a second transaction, while signer and verifier children close their inherited copies so a background FreePBX GPG refresh cannot extend the lock beyond the parent transaction. These paths do not modify phone provisioning, PJSIP peers, or unrelated FreePBX module content.

Scheduled-announcement definitions live in the protected central config, while the execution ledger is a separate Asterisk-owned `0640` state file. The worker claims an occurrence before submitting delivery and fails closed if its worker lock or ledger cannot be opened safely, favoring a missed page over an accidental duplicate. It revalidates the live schedule immediately before claiming delivery. Portable `.config` imports lack execution history and disable imported schedules for review; native FreePBX backup includes the journal and restores it through replay-safe validation. A normal uninstall preserves the local ledger so reinstalling cannot replay a completed occurrence; an explicit purge removes it. Scheduling shares the normal announcement lock and cooldown and does not bypass recipient, audio, or SIP NOTIFY validation.

Executable runtime under `/usr/local/bin/sls_mass_notify`, including Piper, maintenance, and updater code, is owned by `root:root`. Mutable deployment data remains under the Asterisk data folder. The root updater accepts only the official repository, checks release asset hashes and a publisher-signed manifest, accepts normal three-part tags with an optional `-beta` suffix, and executes the installer from the resolved release commit. Automatic updates remain disabled by default.

Phone SIP NOTIFY requests are submitted directly through Asterisk/PJSIP to registered endpoints. Mixed phone families use contact-specific payloads only when every registration URI is resolved and Asterisk can safely route it; otherwise one generic XML document is submitted by endpoint fan-out. Unknown formats also use generic XML. SIP/SIPS URI transport parameters and IPv6 literals are preserved, while control characters and malformed contacts are rejected. Vendor XML support is model-, firmware-, provisioning-, authentication-, and certificate-dependent; do not interpret a successful AMI action as proof that a phone displayed the payload.

Native FreePBX backup records use a manifest with type, restore name, byte count, and SHA-256 for every protected file. Restore is size-bounded, rejects symbolic-link/path escapes, validates config structure and encrypted credentials, checks custom WAV content, stages changes privately, and rolls back on activation failure. Due or completed schedule occurrences are not replayed. A stock FreePBX restore cannot fetch an unknown custom module, so install this module before restoring its module data and protect archives as secrets.

The module does not replace FreePBX system hardening. Firewall rules, TLS certificates, fail2ban policies, OS patching, mail transport security, backup encryption, and SIP trunk security remain the responsibility of the PBX administrator.

## Delivery state and resource limits

Desktop authentication is throttled before expensive credential work. Live streams are capped per client and globally; active credentials are rechecked during a stream. Event acknowledgments are authenticated and restricted to eligible targeted events. Password encryption remains portable by design and is not protection against theft of the complete configuration.

Announcement and weather jobs use bounded, protected journals and claims made before irreversible submission. Known failed announcement destinations can be retried deliberately; uncertain or interrupted submissions are not automatically repeated. This prevents duplicate alerts at the cost of requiring operator review after some crashes. Separate observation, delivery, and external-retry workers prevent slow recipients from holding the observation lock.

The weather outbox is limited to 1,000 jobs and 16 MiB. Pending jobs and external retries expire after one hour; terminal weather/retry records are retained for seven days. API audit retention is 30 days with capacity limits, and operational logs use module-specific rotation. Disk and queue health checks report faults; no unlimited-retention guarantee is made.

## Dependency Security

The `0.1.2-beta` updater verifies an Ed25519-signed release manifest using the already installed publisher public key. The manifest binds the version, tag, installer checksum, and TGZ checksum. The verified archive is passed locally to the installer to avoid a second mutable download. Release signing keys are kept outside the source tree and package; back them up securely before publication. This mechanism is separate from PBX-local FreePBX module signing. A fresh bootstrap still requires trusting the initially downloaded installer and its embedded public key.

New-format releases are versioned, not overwritten: a correction requires a new version and signed assets. The updater intentionally ignores an equal version. Signed manifests authenticate bytes, not code quality, and do not protect against a compromised publisher signing key.

The installer detects native prerequisites and installs only missing Debian packages, creates a dedicated Piper virtual environment with pinned packaging tools and `piper-tts`, and downloads Piper voice models from a pinned repository revision with exact SHA-256 verification. It validates a loopback-only FreePBX AMI host/port, verifies required Asterisk modules will remain available after restart, refuses an unrelated `/usr/local/bin/piper` wrapper, and refuses conflicting reserved SLS System Recording ownership. Release TGZ paths and metadata are validated before extraction, while the build gate rejects credentials, private keys, models, logs, caches, backups, signatures, nested archives, and generated artifacts. Use a trusted network for installation, verify release checksums, and run installers only from the official project source.

The project locally signs its custom module and the FreePBX modules containing managed integration files. The signer resolves the configured FreePBX web user, web root, spool path, and actual GPG home through FreePBX and the operating-system account database. It rejects unsafe paths, repairs ownership and restrictive modes in the selected keyring before importing trust, and serializes key and signature work with a root-owned lock. A new `module.sig` replaces the existing file only after exact FreePBX verification succeeds; otherwise the previous signature is restored. The signing key is generated on each PBX and trusted only in that PBX's FreePBX GPG home. This detects later local file alteration but is not a publisher-distributed release signature or a substitute for verifying the release download.

## Disclosure Target

The goal is to acknowledge valid security reports quickly and publish fixes in the next package when practical. Severe issues affecting authentication, arbitrary file writes, command execution, restore integrity, or unauthenticated alert sending should be treated as urgent.
