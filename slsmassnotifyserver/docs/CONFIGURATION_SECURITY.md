# SLS configuration security and recovery

## Central configuration

SLS stores active settings in `/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config`. Staged changes use `mass-notifications.pending.config` until FreePBX applies them. These files contain the same settings schema and use AES-256-GCM encryption with a fresh 96-bit nonce for each write. Configuration envelopes authenticate the format and key identifier as additional data. Authentication or key failures stop loading; SLS never replaces a damaged encrypted configuration with defaults.

The decoded configuration limit is 2 MiB. The encrypted file limit is 3 MiB. Settings files remain private regular files owned by `asterisk`, with mode `0640`. Journal files contain operational state, such as receipts and delivery attempts; they are separate from configuration.

Migration also encrypts retained legacy JSON settings, including `/var/lib/asterisk/slsmassnotifyserver-settings.json` and its pending counterpart on a standard installation. Their decoded values remain unchanged. An existing unreadable, inaccessible or damaged configuration blocks loading, saving and module activation; only genuinely absent files may use migration sources or fresh defaults.

Older shell settings (`mass-notifications.conf`, `/var/lib/asterisk/slsmassnotifyserver.conf` or a historical `config.ini`) are not settings sources in this release. If one remains from an older installation, preserve it privately for administrator review. Restrict access to the administrator and PBX service account, verify its values against the central configuration, and store any retained evidence in an encrypted archive. Do not source or execute it, publish it or include it in an unprotected backup.

Encryption protects stored configuration against disclosure without its key. It cannot protect secrets from a compromised PBX runtime account: that account needs the key to run SLS. Protect the host, administrative accounts and backup storage accordingly.

## Encryption keys

The root-controlled keyring is `/etc/sls-mass-notify/config-keys.json`. Its directory is owned by `root:asterisk` with mode `0750`; the file is owned by `root:asterisk` with mode `0640`. PHP and worker processes can read keys but cannot replace them. The keyring is outside `/etc/asterisk`, whose permissions vary between FreePBX installations.

SLS validates ownership, file type, permissions, inode identity and path components before reading key material. Key maintenance runs through the existing root maintenance path. It opens no listening port and has no web endpoint that creates or rotates keys.

Keys rotate after 365 days. Routine checks use a protected success marker to avoid scanning configuration backups more than once per day; a due key bypasses that cache. A busy announcement or settings operation defers maintenance until the next run. Rotation keeps retired keys so older encrypted files remain readable. Keyring changes are synchronized before new configuration files are activated. If a later file write fails, both old and new ciphertext remain readable and the next maintenance run completes migration.

Unstarted emergency-call observations retain the SHA-256 fingerprint of the exact configuration file seen when the dial attempt occurred. Any subsequent storage rewrite, including encryption migration or key rotation, invalidates those observations even when decoded settings are unchanged. SLS records the rejection without inferring new recipients or retransmitting the observation automatically.

Do not delete retired keys manually. The keyring permits 128 retained keys and stops rotation with an actionable error if that limit is reached. Any retirement procedure must account for backups retained outside the PBX.

## Backup and restore

The native FreePBX Backup adapter includes encrypted configuration and only the key needed by that configuration. It also captures the scheduling journal, custom tones, SMS consent and spending history, and operational evidence. Temporary snapshots are private and registered for FreePBX cleanup.

**A native backup contains recovery key material. Treat the complete backup as a secret and use protected or encrypted backup storage.** Encrypting configuration while including its key in the same archive does not independently encrypt that archive. This design lets a replacement PBX restore without the original machine's keyring.

Restore verifies the file inventory, sizes, SHA-256 hashes, protected extraction paths and authenticated ciphertext before changing configuration. Restored settings are encrypted with the destination PBX's active key. Source backup keys are not installed in the destination keyring. Older plain native backups remain compatible and are encrypted when activated.

Restore keeps historical delivery evidence out of executable queues. Past schedule occurrences are marked uncertain instead of replayed. Weather, lightning, SMS, external calling and automatic triggers require review before rearming. This prevents an old backup from sending duplicate alerts or restoring an outdated spending allowance.

For a portable configuration export, use the password-protected export in SLS administration. Existing portable exports use Argon2id and XChaCha20-Poly1305; their format remains compatible. Keep the passphrase separately. A plain export contains credentials and should only be used with controlled storage. Export reminders do not send email or automatically disclose configuration.

## Verification

`tools/test_config_crypto.py` exercises PHP/Python interoperability, Unicode settings, duplicate fields, tampering, invalid and missing keys, file permissions, symlinks, shared inodes, FIFOs, size limits, legacy JSON migration, rotation boundaries, partial migration, activity locks, daily check caching and portable native recovery keys.

`tools/test_config_fail_closed.py` runs exact configuration methods as the unprivileged `asterisk` account. It verifies permission failures, missing keys, malformed and tampered settings, staging, request-cache invalidation, protected key permission repair, and safe fresh-install or legacy migration behavior without overwriting existing settings.

`tools/test_freepbx_backup_restore.php` verifies the native manifest and encrypted restore path, including missing and incorrect recovery keys. `tools/test_native_restore_recovery.php` injects activation, storage and rollback faults and verifies that incomplete recovery keeps its evidence. These tests use isolated fixtures and send no notifications.
