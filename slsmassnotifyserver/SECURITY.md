# Security Policy

SLS is beta software for noncritical testing, not a safety or emergency service. The administrator beta warning is separate from Labs activation. It uses a one-use, administrator/session-bound challenge and a server-enforced five-second delay. Each FreePBX administrator has a separate acknowledgment in encrypted configuration; one administrator cannot acknowledge it for another. Existing single receipts apply only to their recorded actor. Portal users and Control API keys cannot acknowledge it.

All SLS email producers reserve capacity in a bounded private journal before entering the local Postfix transport. Queue inspection is read-only, bounded and refuses a backlog of 200 messages or unavailable evidence. Shared submission caps also cover recovery email; uncertain handoffs are not replayed. SLS cron output uses bounded private diagnostics and cannot generate cron mail. These controls do not manage other PBX mail producers or delete queued messages.

The bundled root uninstaller authenticates retained publisher helpers and reviewed stock inventories. It drains notification workers under private leases before teardown and refuses active phone delivery. It never executes web-writable module PHP as root, installs dependencies or approves arbitrary live hashes. Default removal retains encrypted settings, media and delivery history. Failed cron reads cannot replace unrelated jobs; interrupted teardown accepts only approved installed or uninstall states. Destructive data purging requires a separate explicit request.

Collector voicemail submission and expired-recording cleanup take a nonblocking shared worker lease before reading delivery state, entering AMI or changing media. Installation and removal take the exclusive lease, draining existing submissions and preventing new ones while retaining pending evidence. A busy lease leaves the collector free to continue heartbeats. Upgrade preflight also refuses a still-eligible pending fallback from an older collector that predates this lease.

SLS Mass Notifications Server is beta-stage FreePBX software that can send PBX alerts, desktop notifications, SIP NOTIFY messages, webhooks, and optional TTS audio pages. Treat it like infrastructure software: test changes on a non-critical PBX first, restrict administrative access, and keep FreePBX, Asterisk, Debian packages, and this module updated.

## Runtime console

Runtime control requires root. The fixed console entrypoint ignores Python environment overrides, checks protected root ownership, and verifies installed executable bytes against the enrolled publisher generation before control or status. It accepts only fixed command names and manages one fixed SLS service. It never executes configuration-supplied commands or loads FreePBX as root. Changes to notification admission use existing encrypted-config storage and ordered locks, preserve staged edits and coordinate with installation. Malformed runtime state blocks admission. Receipt ingestion and administrative recovery remain available while notifications are stopped.

Enterprise Labs opens its danger notice automatically before the first acknowledgment. Acceptance requires an administrator session, CSRF protection, a session-bound challenge and a full five-second server-enforced wait. One page acknowledgment is stored in encrypted configuration and tied to the notice revision. Dangerous feature activation checks that receipt on the server. Legacy feature-specific receipts remain scoped to their original feature. Acceptance does not enable any feature.

## Redacted support downloads

General Settings can download a small JSON diagnostic report through an authenticated FreePBX POST with a valid CSRF token. The response is an attachment with `private, no-store` caching and a 128 KiB limit; no report is stored on the PBX. Exported fields are explicitly allowlisted. Configuration, credentials, hostnames, addresses, device identifiers, message contents, raw logs, and diagnostic detail strings are never copied into it. Versions, permission modes, health booleans, and anonymous device/queue counts remain visible, so share even this report only with intended support recipients. A `.config` backup is a separate, sensitive download.

Urgent priority affects only prepared announcement audio waiting for shared recipients. It does not grant additional destination access, bypass authentication or cooldown, or interrupt active audio. Dead waiting tickets expire; unsafe queue paths and malformed state fail closed.

## Supported Versions

### Installer and worker hardening

General-announcement state can be recorded before FreePBX bootstrap. Workers run as the PBX runtime account; a root invocation drops privileges before opening job storage. Supervision captures bounded child output, records fixed failure categories rather than raw exceptions, and uses a process-group timeout. Interrupted submissions are marked failed with uncertainty retained and are not replayed automatically. Harmless health probes never submit alert channels.

Installer and updater scratch directories are restricted to safe root-owned `/tmp` and `/var/tmp`, with sticky permissions required when writable by other users. The selected installer directory is preserved across extraction, recovery and protected speech dependency work. Check-only update requests cannot execute the installer; failures remain retryable without weakening TLS or publisher-signature validation.

Upgrade preparation verifies the previous publisher generation before approving recovery. Missing runtime helpers can be restored from those authenticated bytes; existing changed files are never overwritten by this migration. Recognized CPython caches for signed sources are preserved in root-private evidence and removed before the rollback snapshot. Cache hashes identify quarantined evidence only and never authorize execution. Unknown code, links, writable entries and changed source files remain blocking failures. Inventory-only preparation makes no runtime changes.

SLS collector faults cannot abort an ordinary FreePBX reload. That hook uses a bounded read-only health probe and logs only a fixed diagnostic. Installation and restore remain strict, and phone-audio admission still requires fresh authenticated collector evidence. The reload hook neither restarts services nor bypasses admission checks.

Speech synthesis and System Recording conversion enforce output-file limits while decoding, in addition to their process deadlines and workspace reservations. Output at the bound or beyond the configured speech duration is rejected and removed; it is never treated as a complete announcement. These child-process limits do not apply to the parent admission journals or existing logs.

Direct browser-push tests are unavailable when notification cluster coordination is enabled. Manual Weather tests recheck the protected legacy-delivery gate before channel submission and call-file activation. Coordinated tests use the queued path and its witness authority; a successful transport request does not establish a valid failover lease.

Standalone edge dispatch and direct cluster-effect admission enforce the protected boolean runtime policy before claiming queued jobs or effects. A stopped or malformed runtime policy blocks publication without resetting replay history or extending expiry. Read-only status, administrative recovery and authorized receipts for existing published events remain available. Every new edge queue or cluster-effect claim reads current encrypted settings under the shared configuration lock; console stop commits under its exclusive counterpart. The claim releases that lock before notification callbacks. Runtime stop does not revoke an effect already admitted or retract an already published event.

Root installer/uninstaller logs and maintenance locks use exclusive, no-follow creation and descriptor identity checks before writes. Only log files have a legacy-ownership migration: a single-link regular file owned by root or the Asterisk service account can be secured through its verified descriptor. All later log writes use that held descriptor, including after FreePBX ownership changes. Lock ownership remains strictly root-only. Symlinks, hardlinks, special files, unexpected owners, and unsafe parent directories are rejected; kernel temporary-directory protections and shared lock-directory permissions are not weakened. Configuration compatibility migration is narrow and precedes strict type/key validation; it does not relax unknown-field rejection. Shared delivery activity locks and exclusive configuration/backup locks coordinate general announcements without serializing independent workers.

These checks do not establish handset display, human receipt, or compatibility with every PBX deployment. Real-device testing and disposable install/restore testing remain necessary.

In `0.1.4-beta`, installer AMI and API checks use private, per-run temporary storage. Python is checked before protected logging; only a missing interpreter on a recognized Debian 12 FreePBX host is installed automatically. Existing broken or custom interpreter files are not replaced.

Security fixes are currently targeted at the latest release only.

Version `0.1.2-beta` pins the private Piper environment to pip `26.2.0`, addressing [CVE-2026-13346](https://osv.dev/vulnerability/GHSA-qwm4-qh6w-59xr). Dependency checks are point-in-time checks, not a guarantee that the PBX has no vulnerabilities.

| Version | Supported |
| --- | --- |
| `0.1.6-beta` | Current corrected prerelease; noncritical testing only |
| `0.1.5-beta` | Superseded; upgrade to the corrected 0.1.6-beta prerelease |
| `0.1.4-beta` | Upgrade recommended |
| `0.1.3-beta` | Upgrade recommended |
| `0.1.2-beta` | Upgrade recommended |
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

Desktop credentials remain recoverable and individually revocable in the central configuration. Their existing inner encryption key travels with portable exports for migration; decrypted settings therefore contain enough material to recover them. At-rest configuration now adds AES-256-GCM with a separate root-controlled keyring. Protect exports and native archives as secrets. Desktop credentials authorize that client's targeted notifications and software receipts, not administrative Control API actions.

## Configuration encryption

Active, staged and retained configurations use AES-256-GCM, fresh 96-bit nonces, authenticated format/key IDs and 256-bit random keys. Decoded settings are limited to 2 MiB and stored envelopes to 3 MiB. Invalid ciphertext, missing keys, unsafe paths and unreadable existing settings fail closed; damaged state is not overwritten with defaults.

The keyring `/etc/sls-mass-notify/config-keys.json` is `root:asterisk` mode `0640`, under a `root:asterisk` directory with mode `0750`. Key maintenance runs only through protected root helpers and adds no listener or web rotation endpoint. Automatic rotation is due after 365 days, coordinates with announcement/configuration locks and keeps retired keys for old backups. Routine successful migration checks are cached for 24 hours; a due rotation bypasses that cache.

Native FreePBX snapshots contain ciphertext and its recovery key. Restore authenticates both, validates the file manifest and encrypts settings with the destination key. The **complete native archive remains sensitive** and requires secure storage or backup-level encryption. Password-protected portable exports keep their existing independent Argon2id/XChaCha20-Poly1305 format. See [configuration security and recovery](docs/CONFIGURATION_SECURITY.md).

At-rest encryption does not isolate secrets from a compromised PBX runtime account that must read them. No independent penetration test, FIPS validation or enterprise compliance certification is claimed.

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
- Use strong desktop client passwords.
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

SLS advertises the hostname stored in protected central configuration. An authenticated administrator can explicitly migrate the address and individual service ports in General Settings after reviewing the URL preview and local TLS check. DNS or operating-system hostname changes do not automatically change SLS. Hostname changes are not accepted as Control API configuration mutations. Help shows API requests that supply a key, including local health probes. Requests without a key remain in the protected API security journal and do not consume the separate human security journal's capacity.

Alert email uses canonical sender-local-part and domain values stored in protected central config. The local part defaults to `no-reply`; the UI, config import, and Control API validate both fields and reject header controls, schemes, mailbox-in-domain input, IP literals, and malformed DNS labels. Older valid sender settings supply migration values when canonical keys are absent. This setting does not configure or secure Postfix, an SMTP relay, SPF, DKIM, DMARC, PTR/reverse DNS, or any other DNS/mail infrastructure.

Live Weather and Lightning destinations share a bounded dispatcher. Email recipients are scoped to the matching Weather zone or Lightning area; Weather zones also choose specific enabled webhook destinations, while Lightning uses the enabled shared webhook set. Dashboard announcements use a separate protected list of no more than 10 Discord or Discord-compatible HTTPS webhooks and send only to the IDs selected by an authorized sender. Discord-hosted URLs must match Discord's HTTPS webhook shape; compatible receivers require a DNS hostname whose resolved addresses are all globally routable. Requests use certificate verification, validated-address pinning, no redirects, limited retries, bounded payloads, and an idempotency key. Dashboard desktop publication and audio admission precede bounded webhook submission; phone visual delivery follows it. A slow webhook can therefore delay the phone popup without withholding the preceding desktop/audio work. Destination secrets are omitted from API/config responses, UI markup, logs, and safe result records. Manual tests, previews, dry runs, and direct CLI use cannot send external webhook traffic without the internal live-delivery gate.

The chronological Weather dispatcher coordinates cross-zone delivery through a root-path-constrained, no-follow state file and sidecar lock. The journal stores domain-separated hashes rather than phone numbers, desktop usernames, email addresses, or webhook identifiers, rejects non-regular files and invalid schemas, and applies entry, size, and retention bounds. Claims are made before irreversible local or external submission, so a crash favors suppression of a possible duplicate over automatic replay.

Settings participate in FreePBX’s native Apply Config hook and remain staged in a protected Asterisk-owned file until reload. The installer separates fixed privileged installation operations from FreePBX integration: root helpers consume an independently authenticated publisher generation, while PHP, module hooks, database integration and `fwconsole` run as `asterisk`. Maintenance uses the same authenticated phases. Install, update, repair and uninstall share protected maintenance locks; installer worker leases also cover incident policies, ordinary schedules, weather and announcements. Unexpected code or an unreviewed Framework/Dashboard baseline stops activation. The signer cannot approve the current web tree merely because it is present. See [protected trust and installation phases](docs/privileged-trust.md). These paths do not change phone provisioning, PJSIP peers or firewall rules.

Scheduled-announcement definitions live in the protected central config, while the execution ledger is a separate Asterisk-owned `0640` state file. The worker claims an occurrence before submitting delivery and fails closed if its worker lock or ledger cannot be opened safely, favoring a missed page over an accidental duplicate. It revalidates the live schedule immediately before claiming delivery. Portable `.config` imports lack execution history and disable imported schedules for review; native FreePBX backup includes the journal and restores it through replay-safe validation. A normal uninstall preserves the local ledger so reinstalling cannot replay a completed occurrence; an explicit purge removes it. Scheduling shares the normal announcement lock and cooldown and does not bypass recipient, audio, or SIP NOTIFY validation.

Executable runtime under `/usr/local/bin/sls_mass_notify`, including Piper, maintenance, and updater code, is owned by `root:root`. Mutable deployment data remains under the Asterisk data folder. The root updater accepts only the official repository, checks release asset hashes and a publisher-signed manifest, accepts normal three-part tags with an optional `-beta` suffix, and executes the installer from the resolved release commit. Automatic updates remain disabled by default.

Phone SIP NOTIFY requests are submitted directly through Asterisk/PJSIP to registered endpoints. Mixed phone families use contact-specific payloads only when every registration URI is resolved and Asterisk can safely route it; otherwise one generic XML document is submitted by endpoint fan-out. Unknown formats also use generic XML. SIP/SIPS URI transport parameters and IPv6 literals are preserved, while control characters and malformed contacts are rejected. Vendor XML support is model-, firmware-, provisioning-, authentication-, and certificate-dependent; do not interpret a successful AMI action as proof that a phone displayed the payload.

Native FreePBX backup records use a manifest with type, restore name, byte count, and SHA-256 for every protected file. Restore is size-bounded, rejects symbolic-link/path escapes, validates config structure and encrypted credentials, checks custom WAV content, stages changes privately, and rolls back on activation failure. Due or completed schedule occurrences are not replayed. A stock FreePBX restore cannot fetch an unknown custom module, so install this module before restoring its module data and protect archives as secrets.

The module does not replace FreePBX system hardening. Firewall rules, TLS certificates, fail2ban policies, OS patching, mail transport security, backup encryption, and SIP trunk security remain the responsibility of the PBX administrator.

## Delivery state and resource limits

Internal voicemail fallback is scoped to applied Weather/Lightning recipients and authenticated live paging groups. Each service has an independent default-on preference in encrypted configuration. Live paging may record the caller's page; disabling its voicemail option prevents that recording. Manual alert tests and ordinary Dashboard TTS do not deposit voicemail, and external carrier mailboxes are outside this feature.

The protected voicemail helper uses bounded private media and the authenticated phone admission/outcome journal. It rechecks current service policy, recipient identity and mailbox mapping before admission, excludes the paging caller and allows at most one deposit per extension when no selected handset answered. Interrupted or uncertain submissions are not automatically repeated. Native Asterisk voicemail writes the mailbox and handles message-waiting indications; SLS neither writes arbitrary mailbox paths nor changes the PBX's mailbox length/capacity policies. Recordings and mailbox messages contain alert or live speech and should be protected like other PBX voicemail data. A successful deposit is storage evidence, not human receipt.

Desktop authentication is throttled before expensive credential work. Live streams are capped per client and globally; active credentials are rechecked during a stream. Event acknowledgments are authenticated and restricted to eligible targeted events. Password encryption remains portable by design and is not protection against theft of the complete configuration.

Announcement and weather jobs use bounded, protected journals and claims made before irreversible submission. Known failed announcement destinations can be retried deliberately; uncertain or interrupted submissions are not automatically repeated. This prevents duplicate alerts at the cost of requiring operator review after some crashes. Separate observation, delivery, and external-retry workers prevent slow recipients from holding the observation lock.

The weather outbox is limited to 1,000 jobs and 16 MiB. Pending jobs and external retries expire after one hour; terminal weather/retry records are retained for seven days. API audit retention is 30 days with capacity limits, and operational logs use module-specific rotation. Disk and queue health checks report faults; no unlimited-retention guarantee is made.

## Dependency Security

The `0.1.2-beta` updater verifies an Ed25519-signed release manifest using the already installed publisher public key. The manifest binds the version, tag, installer checksum, and TGZ checksum. The verified archive is passed locally to the installer to avoid a second mutable download. Release signing keys are kept outside the source tree and package; back them up securely before publication. This mechanism is separate from PBX-local FreePBX module signing. A fresh bootstrap still requires trusting the initially downloaded installer and its embedded public key.

Corrections receive a new version and signed assets. 0.1.6-beta supersedes the earlier owner-authorized 0.1.5-beta reissue; the updater still ignores equal versions. Signed manifests authenticate bytes, not code quality, and do not protect against a compromised publisher signing key.

The installer detects native prerequisites and installs only missing Debian packages, creates a dedicated Piper virtual environment with pinned packaging tools and `piper-tts`, and downloads Piper voice models from a pinned repository revision with exact SHA-256 verification. It validates a loopback-only FreePBX AMI host/port, verifies required Asterisk modules will remain available after restart, refuses an unrelated `/usr/local/bin/piper` wrapper, and refuses conflicting reserved SLS System Recording ownership. Release TGZ paths and metadata are validated before extraction, while the build gate rejects credentials, private keys, models, logs, caches, backups, signatures, nested archives, and generated artifacts. Use a trusted network for installation, verify release checksums, and run installers only from the official project source.

The project locally signs SLS and the FreePBX modules containing managed integration files only after comparing their full expected inventories against protected trust records. SLS records derive from a publisher-verified release. Framework and Dashboard records require independently authenticated upstream sources and reviewed local overlays, including existing security fixes and branding. A previous local signature or a freshly scanned web tree is not sufficient evidence. FreePBX metadata and signature verification run as `asterisk`; the root signer validates account metadata and rejects unsafe keyring files before privileged permission changes. Expected hashes are checked before signing and again after publication. Verification failure restores the prior signature. The PBX-local signing key remains distinct from the publisher release key.

## Disclosure Target

The goal is to acknowledge valid security reports quickly and publish fixes in the next package when practical. Severe issues affecting authentication, arbitrary file writes, command execution, restore integrity, or unauthenticated alert sending should be treated as urgent.

### Portable encrypted configuration

The default portable export uses PHP Sodium XChaCha20-Poly1305 authenticated encryption with a fresh nonce and Argon2id password derivation (three operations, 64 MiB, fresh salt). Work parameters are fixed and validated before derivation to bound untrusted import work. Configuration plaintext is capped at 4 MiB and encrypted envelopes at 6 MiB. The separate export passphrase is not stored. Wrong passwords or altered envelopes do not stage configuration. Plain export remains explicitly available for older installations and must be protected as a secret. Native FreePBX archives and operational history are not encrypted by this feature.

### Scoped API access and proxy trust

Named Control API credentials store hashes of randomly generated secrets, with explicit permissions and frozen-delivery audience checks. An empty restricted audience grants no send permission. Credentials can be revoked independently; pending settings preserve that revocation. Queued named-credential work rechecks authorization before channel execution and after long preparation/admission waits. API configuration responses redact credential hashes. Control API config mutations cannot manage credentials or trusted proxies. Existing legacy-key authentication remains supported.

Forwarded client addresses are accepted only from explicitly configured immediate proxy CIDRs, walking a validated address chain from the trusted edge. Trusted proxies must overwrite both forwarded address and scheme headers. Missing or invalid headers are rejected; forwarded hostnames are never trusted. A forwarded request does not receive the direct-loopback plaintext exemption. Proxy trust is never inferred automatically.

### Installation recovery boundary

The installer snapshots a fixed static installation inventory in root-private storage before replacement. Failed activation restores validated static bytes and supported service state without running old PHP hooks as root. Operational delivery journals are not rewound. Unrelated current cron entries are preserved. Legacy SLS root jobs remain disabled until an independently authenticated release can activate them; this case is explicitly reported as incomplete automatic recovery, with the original recovery locations retained. Do not delete those locations or repeatedly reinstall over an unresolved recovery fault. Installation security inventories are separate from portable user settings.

### Export auditing and native recovery

Plain, encrypted and native configuration exports must record a bounded audit event before the payload is returned. Audit records identify the FreePBX session username (or CLI), direct peer address, export type, time and event ID; they never contain the exported settings, configuration hashes or passphrases. Untrusted forwarded headers are not used for this attribution. Native operational evidence is retained separately from working state, under private permissions and explicit count/size bounds. Incomplete native rollback preserves recovery material even when status reporting fails. See [recovery and credential replacement](docs/RECOVERY.md) for the conservative rearming and rotation workflow. Native archive encryption remains the backup system's responsibility.

Operator sign-ins, password recovery and human configuration exports use `security-audit.jsonl`, displayed only to PBX administrators under Operator Access. API activity uses `control-api-audit.jsonl`; raw keys, passwords, authenticator codes and reset credentials are excluded. Explicit historical separation moves only attributable human records and preserves ambiguous API evidence. Durable migration markers, verified snapshots and a shared backup barrier protect interrupted moves. Incomplete migration or an unfinished log tail blocks unsafe appends and reports a storage fault instead of discarding evidence.

### Configured locations and audience identity

Location membership uses stable desktop client IDs, plus saved recipient IDs for external voice, email and SMS. The location editor stores only configuration and sends no alerts. A reviewed audience group retains a desktop username-to-client-ID binding; the announcement resolver, incident snapshot and group-derived API authority reject removed, renamed, disabled or replaced desktop identities. Other saved recipient IDs resolve through their existing channel validation and are frozen when a delivery is submitted. Location changes and deletion do not rewrite existing group snapshots. Configured locations do not provide live device positions or grant access by themselves; explicit account permissions authorize operator site/location scopes.

## Generated media

Announcement tone and sequence caches must be regular, single-link files with safe permissions. Reuse validates complete RIFF chunks and 8-kHz, mono, 16-bit PCM; corrupt regular caches are regenerated, and conversion output is verified before atomic promotion. Imported System Recording caches and conversion output also require complete telephone PCM before reuse or promotion. System Recording source capture uses read-only, nonblocking, no-follow descriptors, bounded size/time and stable file identity checks. FIFOs, source changes and incomplete reads are rejected without changing the source recording.

Generated PNG/XML downloads use the central optional network/expiry policy with anonymous device-compatible URLs. Missing or malformed policy storage fails closed; no request header can select a file or override the trusted proxy configuration. Both restrictions default off for existing installations. Deployment readiness verifies the actual rewrite route rather than assuming a static-file 404 proves enforcement. See [media access, bounds and limitations](docs/MEDIA_ACCESS.md).


Scoped local operators use existing PBX authentication and reviewed identity bindings, site/audience authority and per-delivery revocation checks. Nonadministrator views project only permitted recipients and participants. Restored assignments require explicit identity review. See [Operators](docs/OPERATORS.md). Trigger enrollment uses source-bound HMAC, deliberate panic confirmation, strict expiry and durable nonreplay evidence; CAP and device/script limits are described in [Triggers](docs/TRIGGERS.md). Approved scripts are trusted administrator code running with the PBX account's existing authority, not an untrusted-code sandbox.

Publisher-key transitions use root-reviewed overlapping Ed25519 keys, irreversible retirement and signed release expiry. The existing self-signing key remains unchanged. Initialization and emergency replacement are explicit root operations; neither .config nor downloaded metadata can grant publisher authority. All release admission paths share this policy, including protected artifact enrollment. Preserve the initialized marker and root ledger during disaster recovery. See [Release trust](docs/RELEASE_TRUST.md).

Dedicated operator accounts use mandatory password plus time-based authenticator
verification. Credentials and replay state stay in the protected central config.
The editor and redacted exports exclude authentication material. Password hashes
use Argon2id (64 MiB, four passes) with a 600,000-iteration PBKDF2-SHA256 fallback;
anonymous password work is limited to two concurrent requests (128 MiB total
derivation memory), with kernel-managed locks and retryable HTTP 429 responses;
seeds use AES-GCM bound to account ID. Decrypted settings contain that inner
key; the complete configuration has separate AES-256-GCM protection and remains private. OTP/recovery consumption and resets use the settings lock.
Sessions, independent password/MFA rate limits, origin/CSRF guards and channel/
recipient scopes fail closed. Full PBX administrators retain recovery in the PBX
account editor. Tests include RFC 6238 vectors, concurrent replay and real private
HTTP enrollment/reset/revocation flows.

Operator password recovery stores only SHA-256 hashes of random 256-bit link
credentials, bound to the current authentication version and a fixed 24-hour
expiry. Links are single use, revocable and delivered using the configured HTTPS
origin, never an incoming Host header. Browser fragments keep credentials out of
HTTP URLs; the browser clears them before a same-origin CSRF-protected POST.
Both email and administrator links require the enrolled TOTP and consume it
atomically. Backup codes do not bypass reset verification. Two recovery emails
or two failed self-service verifications require administrator assistance; failed
or uncertain mail submissions count and are not retried. These requests do not
disable the account's normal password sign-in.

Failed passwords use protected atomic storage with per-IP rolling windows of
6/5 minutes, 12/10 minutes and 20/24 hours. Parallel checks reserve budget before
hash work. Twenty failures start a fixed 24-hour address lock. Only a successful
link-plus-TOTP password reset clears that address and account's short limits;
global abuse protection remains. Audit events omit all password, code and reset
credential fields. Critical recovery changes require a durable audit authorization
before they are saved; completion is recorded only after configuration persistence.

## Production-readiness review

The module hardens configuration readers, native recovery, API projections, automation administrator checks, scoped location permissions, SMS callback replay protection and bounded status/receipt storage. Dashboard idle polling uses a 60-second cooldown and pauses in hidden tabs. Automated fixtures run in private mount/network/PID namespaces with production configuration, sockets and keys hidden. They do not establish physical handset display, carrier acceptance, remote NAT behavior, inbox delivery or production fleet throughput. Independent assessment and deployment-specific qualification remain necessary.

## Enterprise Labs isolation

Enterprise features start disabled and add no listener, firewall rule, PBX restart or peer enrollment automatically. Only a current FreePBX administrator can activate Labs namespaces or accept dangerous-feature warnings; operator portal accounts, Control API credentials and UCP users cannot do so. All configuration remains in the AES-protected central settings. TLS client keys and authority certificates are explicitly provisioned protected local files.

Notification HA uses pinned mutual TLS, timestamped HMAC requests, durable replay protection, an independent witness and an authority check at each external effect. Missing or damaged established journals stop delivery. Ambiguous effects cannot be retried automatically; witness recovery requires a fresh epoch and reviewed reconciliation. This does not provide full PBX failover and must not be used for production or life-safety reliance.

OIDC/SAML uses locked upstream protocol libraries, validated signatures/issuer/audience/nonces and explicit grants to existing enabled accounts. Directory edits require preview and revision-checked application. Subscriber tokens are hashed, expiring and bound to their enrolled recipient; responses use CSRF protection and remain separate from transport receipts.

Private floor plans are bounded, decoded and re-encoded PNG/JPEG files outside document roots. Plans and incident responses are projected through current site/audience permissions. Door mutations and public-warning origination require explicit configured capabilities and their own warning acceptance. Provider operations preserve uncertainty rather than repeating an unconfirmed action. These interfaces have isolated adversarial checks; no hardware certification, authority approval or independent external penetration test is claimed.

The legacy FreePBX Sysadmin disk-space widget uses the signed module’s local Chart.js 2.9.4 bundle. Only its exact legacy CDN script URL is redirected; other AJAX requests are unaffected. The upstream MIT license, source URL and file hashes are included in the dependency inventory.

## 0.1.6-beta installer boundaries

Root helpers are admitted against authenticated release bytes before configuration or update-policy imports. Protected imports compile checked source rather than using generated bytecode. Crypto loads only after generation authentication. Missing AES prerequisites produce a specific error without exposing configuration or keys.

Spool ownership repairs use held directory descriptors and refuse symlinks or replaced entries. Module activation and rollback rename only within the module filesystem. Voice transfers enforce catalog size, checksum, HTTPS redirects and elapsed-time limits, preserve the previous model on failure and remove interrupted temporary downloads. Updater installer, manifest, signature and package transfers enforce their own byte limits through a shared protected stream writer, independently of response headers. Downloaded code remains subject to publisher verification before execution.

Inspection collects root-private evidence without mutating installed settings, services, packages, keys, trust pointers or failure notices. It may require network access for signed artifacts; offline evidence is supported. Unsupported collector service states are rejected before normalization or maintenance changes. Repair-mode idle inspection does not waive active-call, pending-spool or worker checks, and final verification requires orphan reconciliation and unchanged closed history.

Dial-in paging checks the caller’s current Asterisk channel unique ID, device-to-user mapping and registered contact before excluding that contact. The saved group audience is identical in PHP and Python. Unknown or ambiguous identity fails closed. Public notification schema 1 and SSE protocol 2 remain unchanged. The served-PHP installer probe accepts only direct loopback GET requests and returns prerequisite names; it exposes no settings, credentials or administrative actions.

## Additional 0.1.6-beta controls

Interrupted installations use a root-private, synchronized transaction journal.
Only authenticated saved installer/runtime generations can perform recovery.
Unfinished evidence is retained and blocks a new mutation until reviewed
recovery succeeds. Signal handling cannot convert an interrupted install into a
successful cleanup. A completed journal marker is preserved even when its bulky
recovery data is retired. Stock Dashboard bootstrap verifies official signatures;
unknown local differences still require review.

Served PHP prerequisites are checked independently of CLI PHP. The protected
job/collector profile requires PHP to run as the Asterisk service account with
`exec`, `proc_open` and the documented extensions available. The installer does
not grant a different web account root access or change global PHP policy to
circumvent this requirement.

Phone display inventory is private, size-bounded and expires after 90 seconds.
It is never an authorization grant or the final registration check for delivery.
Shared speech admission limits concurrent synthesis, waiting work, generated
bytes and remaining free space. Cached media cannot bypass validation, and
active references are protected during cleanup.

`Idempotency-Key` protects ordinary Control API announcement submission for
30 days. Keys bind to the authenticated credential, canonical request and its
current permissions. A claimed submission with an unknown result is not replayed.
Recovery retains replay fences and archives delivery evidence without restoring
work into execution.

Optional operator expiry is enforced on authentication, existing sessions,
current permissions and queued delivery authorization. Changing the expiry
revokes the old authentication binding and reset links. Password recovery does
not extend account access; full FreePBX administrators retain recovery access.

Web Push uses RFC 8291 authenticated payload encryption and RFC 8292 VAPID.
Enrollment requires the current email-verified subscriber identity. Queue
snapshots bind to exact subscription identities and keys; changed or revoked
endpoints cannot receive an older queued alert. VAPID and connector credentials
stay in the encrypted `.config` and are redacted from public projections.
Provider acceptance remains separate from display and human-response evidence.

The dependency inventory includes embedded JavaScript and vendored PHP/Python
components. Numeric dashboard charts no longer include Moment 2.24.0. OSV checks
fail on reported advisories or an incomplete scan. A point-in-time advisory scan
and isolated tests are not an external penetration test or a security guarantee.

## Legacy runtime permissions

Authenticated upgrades can tighten recognized legacy worker and phone-admission files from mode0644 to0640. Recovery runs only as root, opens relative to validated directory descriptors with no-follow semantics, and checks ownership, regular-file type, single-link identity and bounded size before changing permissions. It preserves contents and lock inodes. Recognized central configuration is authenticated and decoded before changing its mode; recovery does not rewrite settings or rotate keys. Unknown layouts require review; runtime readers continue rejecting unsafe files.

For active/pending central configuration and its empty mutex only, recovery accepts known root or Asterisk ownership at modes0600,0640 or0644 and normalizes ownership to Asterisk. Worker/admission journals retain their stricter service-owner requirement. A missing settings mutex is created exclusively after validating every present configuration; existing mutexes are never truncated or replaced. If a later prerequisite fails, the new empty private mutex remains available for retry. Configuration coordination stays in the authenticated worker keeper, with no-follow/nonblocking opens and checks before and after acquiring the lease. Shell and unprivileged PBX children do not reopen or inherit the configuration lease. Encryption keys and executable ownership are outside this recovery scope.

The installer uses FreePBX's native chown blacklist for the SLS data directory, preventing recursive startup normalization from exposing private journals. Existing unrelated directives are preserved; conflicting custom SLS directives require review. The collector must regain a fresh authenticated heartbeat before worker pause and upgrade activation. Ordinary FreePBX reloads continue with a warning if the collector is unavailable, while new SLS phone-audio admission remains blocked.
