# Security policy

SLS Mass Notifications is beta software for noncritical testing. It is not an emergency or life-safety service. Restrict administration, keep the host patched and qualify changes on a separate test PBX.

## Reporting a vulnerability

Report sensitive issues through [GitHub Private Vulnerability Reporting](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/security/advisories/new). That report is visible only to the repository's security collaborators. If that channel is unavailable, email `gabe09267@gmail.com` with a short, redacted description and ask for an encrypted transfer method before sending logs or attachments. Do not use the public Discord server for sensitive reports. Nonsensitive defects can be reported in [GitHub issues](https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server/issues).

Include the SLS, FreePBX and Asterisk versions, affected interface, reproduction steps and redacted evidence. Authentication bypass, unauthorized sending, arbitrary file writes, command execution and restore-integrity failures require prompt review. The project aims to acknowledge reports and publish fixes when practical; no response-time guarantee is offered.

Do not publish configuration, API keys, passwords, authenticator seeds, AMI credentials, provider tokens, signing keys, reset links or private alert content. The [redacted support report](docs/DEPLOYMENT_READINESS.md) is separate from a sensitive configuration backup.

## Supported releases

Security fixes target the latest published prerelease, currently `0.1.8-beta`. Earlier betas should be upgraded after reviewing the supported installation profile. A signature authenticates release bytes; it does not certify security, performance or delivery reliability.

Corrections receive a new version and signed assets. The updater does not replace equal versions. See [release trust](docs/RELEASE_TRUST.md) and [installation](INSTALL.md).

## Administrative and authentication boundaries

FreePBX administration and the SLS operator portal have separate authentication. SLS does not replace FreePBX or UCP login and exposes administration through the PBX administrator panel. Operator logins grant only their saved roles, actions, channels and recipients. Current permissions and identity are rechecked before queued delivery. Disabled, expired or replaced identities cannot authorize old work.

Operator passwords use salted Argon2id with 64 MiB and four passes, or PBKDF2-HMAC-SHA256 with 600,000 iterations when Argon2id is unavailable. Two concurrent password slots bound derivation memory to 128 MiB. TOTP, an unused recovery code or an explicitly enabled, enrolled passkey follows password verification. Initial authenticator enrollment and passkey management require TOTP. Seeds are account-bound encrypted values; recovery codes are hashed. Consumption and credential changes commit under the configuration lock.

Portal sessions use HTTPS, Secure/HttpOnly cookies, session rotation, expiry, CSRF, origin checks, no-store responses and a restrictive Content Security Policy. Password and MFA limits are separate. Failed passwords are limited per IP to six in five minutes, twelve in ten minutes and twenty in 24 hours; the twentieth failure starts a 24-hour address lock. Pending checks reserve budget before hashing. Mutable failure-state validation and replacement share the permanent transaction lock; type, ownership, permission, link and size restrictions remain mandatory. Throttled or busy work returns `Retry-After`.

Password-reset credentials are random, hashed, single-use, revocable and expire after 24 hours. URLs use the configured HTTPS origin; credentials travel in fragments removed before a CSRF-protected POST. Reset requires the existing TOTP and does not extend account expiry. Two self-service recovery email attempts or two failed verifications require administrator assistance. Uncertain mail is not replayed. See [operator access and recovery](docs/OPERATORS.md).

SSO administration is in **Operator Access**. OIDC/SAML verifies the configured provider, signature, issuer, audience, nonce and explicit local account grant, then requires the local operator authenticator. Provider role/email claims cannot create accounts or grant authority. Enabling SSO requires an enabled, enrolled, unexpired local recovery administrator. Portal accounts, Control API keys and UCP users cannot configure federation. See [operator SSO](docs/ENTERPRISE_IDENTITY.md).

Operator, API, directory and subscriber changes use recoverable active/pending settings transactions under the central configuration lock. A failed second write restores the prior protected files; crash recovery refuses unknown intervening changes. Unrelated staged edits are retained.

The beta warning requires a separate, one-time acknowledgment from each FreePBX administrator, a session-bound challenge and five seconds of visible/server-verified delay. Valid acknowledgments survive upgrades, including receipts saved by 0.1.6-beta and 0.1.7-beta. Labs activation has its own revision-bound receipt. Neither acknowledgment enables a feature or authorizes a notification.

Paging adopts a zero-byte legacy PIN-attempt file only when its permanent sidecar is also uninitialized. File type, ownership, permissions, link count and stable identity checks still apply. A durable or partial initialization marker prevents reinitializing empty state; malformed nonempty history remains blocked. Paging outcomes are recorded without entered PINs or raw AGI responses.

Live paging keeps caller input muted until a one-shot readiness callback verifies the admitted origin, current configuration and recipient outcomes. The caller-only tone continues through the final protected check and stops as input opens, avoiding a verification delay after the cue. Missing evidence or a failed cue leaves input muted, with a bounded setup timeout.

## APIs, recipients and proxy trust

Desktop clients use individual credentials over HTTPS. Polling, SSE and acknowledgments enforce enrollment-bound recipient authorization; a reused username cannot inherit another enrollment's events. Event IDs, publication times and absolute expiry remain stable. An application receipt does not mean a person read a message. Notification schema 1 and SSE protocol 2 remain compatible.

Streams and authenticated traffic have per-client/global limits; failed-login protection is separate. PHP-FPM uses cursor-aware polling to avoid holding shared PBX workers. Throttling returns `Retry-After`. Fleet limits are admission budgets, not throughput certification.

Unchanged desktop rate-limit registry reads share locks. A request requiring a write releases its shared leases, then rereads under exclusive locks before committing. Current authentication remains mandatory on every request. Browser-display responses are bound to the active connection and credentials; disconnecting aborts obsolete requests.

The Control API starts disabled. Named credentials contain hashed secrets and explicit scopes/audiences, can be revoked independently, and are rechecked before queued effects. Empty restricted audiences grant no sending authority. Config responses redact secrets; named config credentials cannot manage API credentials, trusted proxies or the advertised hostname.

`Idempotency-Key` binds ordinary API announcements to the credential, canonical request and current authority for thirty days. Repeated identical accepted requests return the existing result; conflicting or uncertain claims cannot authorize another send. Retain replay history through recovery.

Forwarded addresses/schemes are accepted only from explicitly configured immediate proxy networks. SLS validates the chain from its trusted end; trusted proxies must overwrite incoming forwarding headers. Forwarded hostnames are not an authority, and forwarded requests receive no direct-loopback plaintext exemption. SLS address changes use protected administrator settings; DNS/OS hostname changes do not migrate them automatically.

Administrator and API inputs enforce object, depth and byte limits, reject duplicate keys/nonfinite values, and retain role/CSRF checks. Recipient envelopes prevent native PHP form-variable limits from silently dropping a selection. Scoped locations use stable device identities and reviewed snapshots; assignments alone do not establish consent or presence.

External voice admission inspects the supported FreePBX route before queuing and again on its bound SLS Local channel. Arbitrary predial hooks remain unsupported. An extension-only callback is accepted only when its leading user/channel guards prove that every SLS Local call returns before the callback body. The full observed context remains in the route fingerprint; SLS does not change or execute custom callback files during inspection.

## Configuration, secrets and backups

Active, staged and retained settings use AES-256-GCM with fresh 96-bit nonces, authenticated format/key identifiers and random 256-bit keys. Decoded settings are bounded to 2 MiB and stored envelopes to 3 MiB. Missing keys, damaged ciphertext or unsafe existing files block access instead of replacing settings with defaults.

The central file is `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`, owned by Asterisk at mode `0640`. Its separate keyring is `/etc/sls-mass-notify/config-keys.json`, `root:asterisk` mode `0640` beneath a mode `0750` directory. Protected root maintenance rotates the active key after 365 days and retains recovery keys. This creates no rotation endpoint or listener.

At-rest encryption cannot isolate secrets from the PBX runtime account that needs them. Decrypted settings and complete exports contain reusable credentials. Desktop inner encryption is portable and cannot protect against theft of the complete configuration.

Portable passphrase-protected exports use Argon2id and XChaCha20-Poly1305. Their passphrase is separate and not stored. Native FreePBX archives include configuration recovery keys: **protect or encrypt the complete archive**. Export auditing must succeed before bytes are returned; an audit entry proves preparation, not successful download.

Restore checks inventory, sizes, hashes, paths and authenticated ciphertext. It uses the destination key, preserves live evidence and disables restored automation pending review. Historical queues, incidents and desktop events are not replayed. Incomplete recovery retains its staging/rollback locations. See [configuration security](docs/CONFIGURATION_SECURITY.md) and [backup recovery](docs/RECOVERY.md).

## Protected runtime, installation and removal

Executable runtime under `/usr/local/bin/sls_mass_notify` is root-owned. Mutable state is separate. Root-started delivery workers drop to Asterisk before configuration/module bootstrap; their restricted environment excludes publisher-download tokens. Root control accepts only fixed `slsconsole` commands after verifying executable identity against the enrolled publisher generation.

Install, update, repair and removal share private maintenance/worker/configuration leases. Root helpers require independently authenticated publisher bytes. FreePBX PHP hooks and database integration run as Asterisk. Framework/Dashboard approval requires signed upstream sources and reviewed overlays; an arbitrary live inventory or old local signature cannot approve changes. Inspection does not activate code or mutate protected settings, services or trust.

Downloads have HTTPS, size, time and hash bounds. Piper uses pinned hash-locked binary wheels and verified voice models. Protected imports compile authenticated source rather than executing legacy bytecode. Served PHP is checked independently of CLI PHP. Unsupported layouts, permissions or service states require review; the installer does not weaken host policy to force success.

Offline candidates require the publisher signature, signed manifest and an explicitly trusted archive SHA-256. The digest is exactly 64 hexadecimal characters. Archive validation rejects duplicate or ambiguous paths, links, special files, writable executable payloads, oversized content and ambiguous module metadata before extraction. A checksum alone cannot authorize installation.

Transactions retain authenticated recovery programs and snapshots before mutation. Recovery preserves configuration and newer delivery history. Failed transitions retain their last valid stage; incomplete rollback blocks another mutation. Module activation uses same-filesystem renames. Root logs, locks, snapshots and spool repairs use bounded identity/type/ownership checks.

Static web and Asterisk integration snapshots accept standard FreePBX group-write modes only for root/Asterisk ownership with the Asterisk group. They preserve that metadata through rollback and do not grant root execution. Root runtime, signing/trust files and Piper compatibility directories still reject group writes. World-writable files and unrelated writable groups remain rejected. Read-only inspection validates the complete static inventory before worker coordination.

Legacy permission repair is narrowly scoped to known root/Asterisk ownership and modes after previous-release/configuration authentication. It preserves bytes and lock inodes and rejects links, special files and unknown contents. FreePBX's chown exclusion protects the SLS data directory. New phone admission requires a fresh authenticated collector heartbeat; an unavailable collector remains nonfatal to ordinary FreePBX reloads.

The included uninstaller works locally without downloads or a healthy speech engine. It authenticates teardown helpers, drains SLS work, refuses active delivery and preserves configuration, media, history, logs and recovery keys by default. Help and unsupported arguments never begin host operations. Destructive purge requires a separate explicit request. Failed cron reads do not erase unrelated jobs. See [installer recovery and removal](docs/INSTALLER_OPERATIONS.md).

Publisher Ed25519 signing and PBX-local module signing are distinct. Private release keys stay outside the repository; bounded validated key bytes feed OpenSSL without reopening the selected path. Publisher rotation, expiry, irreversible retirement and emergency recovery require explicit root review. Downloaded metadata and `.config` cannot establish signing authority.

## Delivery state, files and resources

Protected journals use bounded validated state, permanent history markers, locking and atomic synchronized replacement. Missing or damaged established evidence stops new admission instead of resetting replay, consent or spending history. Individual storage helpers document whether they use descriptor-relative no-follow access or checked pathname replacement; those guarantees apply only to the helper and path that implements them. No general filesystem-race guarantee is made for all mutable state.

Queues claim irreversible work before submission. Accepted or uncertain calls, SMS, webhook, mail and physical effects are not automatically replayed. Scheduled deadlines remain absolute through preparation and delivery; active playback is not interrupted to serve urgent work. Registration, queue and speech limits refuse oversized/unavailable work without silently truncating recipients.

Speech/recording conversion has process, duration, output-byte and free-space bounds. Oversized speech is rejected rather than cut off. Cleanup preserves active references and incomplete inventories. Phone input/AMI operations use cumulative monotonic deadlines and bounded buffers, including partial lines. Handset provisioning and physical outcomes still require acceptance testing.

Generated image/XML URLs retain anonymous compatibility access by default. Administrators can require event-bound desktop authentication and short-lived exact-file phone capabilities. Network and absolute download-expiry policies still apply; malformed policy fails closed. Random URLs do not prove recipient identity. Private floor plans use authenticated, permission-checked responses outside document roots. See [media access](docs/MEDIA_ACCESS.md).

Generic webhooks use verified HTTPS, public-address validation/pinning, no redirects and bounded payloads. Optional HMAC-SHA256 covers `timestamp + "." + event_id + "." + raw_request_body`; receivers must verify freshness and deduplicate IDs. Discord URL tokens and provider-specific formats retain their own contracts. One-way acceptance is not human receipt.

Fresh-install email is disabled. Every SLS mail producer reserves shared bounded capacity before Postfix. Read-only queue checks refuse unavailable evidence or a backlog of 200 messages; uncertain handoffs are not automatically replayed. Cron uses fixed bounded runners and private output, preventing SLS cron mail floods. These controls do not manage unrelated PBX mail or delete its queue.

Actual Weather/Lightning and recorded live paging can use default-on internal voicemail fallback. It excludes the caller and extensions with a selected handset answer, rechecks policy/mailbox identity, and submits at most one eligible deposit. Manual tests and ordinary Dashboard TTS do not deposit voicemail. Native Asterisk owns mailbox storage/limits; a deposit is not proof of listening. External carrier voicemail is outside this feature.

## Audit and interface behavior

Human sign-ins/recovery/exports use the private security journal under Operator Access; API decisions use the separate Control API journal. Neither contains passwords, authenticator codes, bearer credentials or reset proofs. Interrupted migration/compaction retains synchronized evidence and stops unsafe appends. Optional syslog handoff does not prove remote durable receipt. See [audit collection](docs/AUDIT_FORWARDING.md).

Support downloads allowlist bounded anonymous diagnostics and omit settings, identities, addresses, content and raw logs. Configuration exports are separately sensitive. Current-health recovery does not erase historical evidence.

Dashboard/admin requests have complete-response deadlines and do not automatically retry unconfirmed changes. Widget replacement aborts its outstanding requests; busy editors preserve their submitted selections and restore controls after failure. Warning failures leave their access gate closed. Rendering starts no provider call or delivery.

## Labs and external qualification

Additional enterprise integrations start disabled and create no listener, firewall rule, peer enrollment or PBX restart merely through configuration. Only current FreePBX administrators can activate them. Failover/mirroring, enterprise login, door actuation and public-warning origination require the Labs danger acknowledgment and must remain confined to nonproduction qualification.

The default-off Enterprise Labs master is separate from warning acceptance. Feature manager requires the current administrator, CSRF protection, a complete allowlisted boolean selection, a revision check and explicit agreement to the displayed warning. Master and child changes commit together to active and pending settings while preserving their separate configured values. Disabling Labs stops new Labs effects and browser-display streams; bounded valid receipts and authenticated removal remain available. Ordinary phone/desktop alerts and the separate operator SSO policy remain independent.

Notification HA requires pinned mutual TLS, signed messages, an independent witness, durable replay/uncertainty evidence and authority before effects. It is not full PBX failover. Missing authority/history suspends delivery; copying a configuration or restoring an old witness does not grant sending authority.

Directories require complete reviewed snapshots and revision checks. Subscriber proofs are hashed, expiring and person-bound. Web Push uses RFC8291/RFC8292, freezes subscription identities and retains absolute expiry. Private images and participant responses enforce current site/audience permissions. Physical/public actions require explicit capabilities and immutable claims.

Approved scripts execute sealed reviewed bytes as Asterisk, with a restricted environment, no-new-privileges and resource/deadline bounds. They remain trusted administrator code with the PBX account's authority. Sensor examples use signed, enrolled inputs and do not supervise a fire panel. Device/provider acceptance is separate from software receipts.

The dependency inventory covers declared Python, vendored PHP and embedded JavaScript components with their licenses and provenance. Advisory scans are point-in-time checks; OS packages and other FreePBX modules need their own maintenance. The local dashboard Chart.js bundle replaces only the exact legacy CDN request. See [dependencies](docs/DEPENDENCIES.md).

Isolated fixtures use private mount/network/PID namespaces, read-only inherited storage and disposable writable overlays. They are trusted project fixtures, not a boundary against hostile root code. Local tests and advisory checks do not constitute an independent external penetration test, compliance certification, physical-device acceptance or full-PBX disaster-recovery/fleet qualification. See [qualification boundaries](docs/QUALIFICATION_MATRIX.md).

## 0.1.8-beta qualification boundaries

New optional authentication, browser, directory, radio, media, mail-evidence and
script features start disabled. Passkeys affect the separate operator portal,
not FreePBX authentication. Current recipient identity and credential expiry
remain authoritative even when polling uses a derived cache. Provider acceptance,
software receipts and a completed browser notification request do not prove that
a human received or acted on an alert.

Opt-in phone capabilities are short-lived exact-file URLs and should be treated
as secrets. Scoped CA enrollment never disables TLS verification or modifies
global trust. Restricted scripts still require administrator-approved code and
positive target-host isolation qualification. Hardware, provider and paired-host
Labs acceptance is documented separately; private fixtures are not an external
penetration-test certification.

Enterprise Labs administrator routes require the persisted timed warning acknowledgment before returning configuration or executing an operation. Warning acceptance requires the existing authenticated administrator, CSRF token, session-bound single-use challenge and server-enforced delay. Acknowledgment does not enable an integration. Meeting accounts isolate credentials, renewal claims and local revocation by provider; settings responses redact every saved secret.
