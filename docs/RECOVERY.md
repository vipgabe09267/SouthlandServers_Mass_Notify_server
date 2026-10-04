# Backup and recovery

Use **General Settings → Config Backup** for an encrypted, portable configuration export. Its passphrase is separate from the PBX configuration and is never stored by SLS. This export contains credentials, templates, recipient definitions and settings; it does not contain recordings or delivery history. The compatibility `.config` download is unencrypted. Both exports and native backup preparation record an audit event before their bytes are returned. If audit storage or enabled forwarding fails, the export is withheld with a specific error. A recorded export means the server prepared the file, not that a browser downloaded or stored it successfully.

Native FreePBX module backups include configuration, schedule execution state, custom tones, SMS continuity when present, and a separate `operational-evidence.jsonl` file. Protect the entire FreePBX archive with your backup system's encryption and access controls. Encrypted configuration download does **not** encrypt native archives. Install this custom module on a replacement PBX before restoring its module backup.

The central `.config` uses AES-256-GCM and a separate root-owned keyring at
`/etc/sls-mass-notify/config-keys.json`. Retain that protected keyring with any
older encrypted configuration or local configuration backup that needs it.
Uninstall preserves the keyring, its lock and maintenance marker even when
`SLS_MASS_NOTIFY_PURGE_CONFIG=1` removes module data, and reports the retained
recovery location. It does not delete recovery keys automatically. Native
FreePBX archives include the key required by their configuration snapshot;
portable passphrase-protected exports use their own encryption and do not depend
on this local keyring. Review retained archives before manually retiring keys.

## Operational evidence

The operational snapshot preserves recognized weather deduplication files, cross-zone claims, queued weather delivery records, lightning storm and provider quota state, phone call evidence and daily reservations, desktop event publication and receipt records, announcement jobs, incident histories and schedule state. Private payloads can contain recipient addresses, message text and provider routing, so handle this evidence like the configuration. Runtime code, credentials from the central configuration, models, generated media, lock files, live process identities and unrelated files are not included in this separate evidence file. Configuration and custom tones are backed up separately; ordinary FreePBX call records and external log archives need their own backup policy.

Each captured file retains its original bytes, hash and capture time. Files are checked for links, unexpected types, size, concurrent writes and replacement. This is a bounded collection of individual snapshots, **not** an atomic snapshot of every subsystem. A source file that is busy or changes causes backup failure rather than an accepted partial copy. The bounds are 25,000 inspected entries, 16 MiB per source file and 64 MiB for the encoded archive. Review retention and retry if a bound is exceeded; nothing is silently dropped to fit.

Restore verifies the manifest and every evidence record, then retains the archive under:

```
/var/lib/asterisk/SLS_Mass_Notifications_Plugin/recovery-archives/<sha256>.jsonl
```

The directory is mode `0700`; archives are `0600`. Identical restores reuse the same verified archive. At most five archives and 256 MiB total are retained. A full recovery directory stops another restore with an actionable error; it never deletes older recovery evidence automatically. Move reviewed older copies to protected backup storage before retrying. A retained archive can remain after a later configuration-restore failure; that is evidence, not a successful activation.

## Rearming after restore

1. Confirm the restored hostname/HTTPS port, recipients, credentials, PBX routes, time zone and clock. Check **Deployment Readiness** and the restore result before allowing automation.
2. Review current NWS alerts and lightning conditions using an independent source. Restore disables NWS and lightning automation, even when historical evidence exists. Deduplication and storm records are retained for review, not installed as current state. Never infer an all-clear from a missing record or a restored inactive storm. Enabling a service resumes its normal current observations and may announce a currently active event again; review that decision first.
3. Review schedules. Already due or uncertain occurrences are not replayed. Missing execution history or a changed time zone disables affected schedules. Portable configuration import disables schedules because it contains no execution journal.
4. Review external voice routing and the call allowance before re-enabling it. Restore disables external voice: a replacement PBX may not contain all calls made since the backup, and a historical budget must not reset newer usage. Existing live phone evidence is never overwritten by the evidence archive.
5. Review SMS consent, opt-outs, provider routing and spending before re-enabling SMS. Its separate continuity merge preserves existing reservations and opt-outs conservatively; it does not resubmit historical provider requests.
6. Review unfinished announcements and incidents as uncertain historical work. Archived queued/running jobs, escalation timers and planned incidents are **never** added to active queues. Desktop history is never republished into the live inbox. Reconcile with responders before explicitly launching any replacement incident or announcement.

Existing operational data on the target PBX is preserved. Do not manually copy the evidence's historical JSON into live queue, storm, incident or phone-admission files. Such a copy can replay an alert, reset a spending limit, misattribute a receipt or issue a false all-clear. The archive deliberately has no automatic extraction-to-live-state command. Older backups without operational evidence remain supported, with an explicit warning and the same conservative service rearming.

## Failed restore

If native restore cannot finish, SLS attempts to recover the previous protected files and verifies their bytes and modes. If rollback cannot be verified, it retains the private staging and rollback directories and prints both locations. Preserve both, inspect the original failure and restore the failed postconditions before retrying. A status-file write failure does not erase recovery evidence. Successful file rollback permits cleanup; active delivery history is not rewound as part of rollback.

The isolated release tests cover corrupted manifests, unsafe paths and links, busy/changing files, archive bounds, idempotent archival, unchanged live state, failed activation, failed rollback writes and altered rollback contents. A full restore on a separate PBX, with its own trunks, storage, FreePBX modules and approved endpoints, remains a required deployment acceptance check. These tests do not certify physical power-loss behavior or restore an active emergency response automatically.

## Credential replacement after a leaked backup

Treat every credential contained in a stolen complete configuration as exposed. Changing only the desktop encryption key does not invalidate copied passwords.

- **Named Control API credentials:** create a replacement with the same reviewed scopes/audience, update the integration, verify authentication with a read-only request, and revoke the old credential. Revocation is immediate and preserved in staged settings. Revoke first if ongoing misuse is suspected; integrations will fail until updated. Never test replacement send credentials against an unapproved audience.
- **Legacy Control API key:** use **Regenerate Control API Key**, then Apply Config and replace it in its consumers. This has no overlap window. Prefer separate scoped keys for new integrations.
- **Desktop credentials:** change each affected desktop's password in General Settings, save and Apply Config, then update that desktop's connection. Keep its username and generated client ID to preserve identity. Existing streams recheck credentials; revoked connections must fail. Verify with connection health before sending an approved test alert. Disabling a client stops access while it is being repaired.
- **Paging PINs:** use each group's Randomize PIN control, save and Apply Config, and securely notify only its authorized callers. Keep Require PIN enabled where required by policy.
- **SMS, weather and webhook credentials:** replace/revoke the provider secret at its source, then update the corresponding SLS settings and apply them. Webhook URLs can themselves be secrets. Coordinate incoming provider callback validation when rotating SMS credentials. Do not assume editing SLS revokes a provider's old token.
- **AMI and PBX credentials:** coordinate rotation with the PBX administrator so Asterisk and the matching protected SLS setting change together. Verify the phone-event collector before enabling audio. SLS cannot revoke unrelated PBX or provider accounts.

Finally, review audit and delivery history, remove exposed downloads from insecure storage, and create a new encrypted backup with a different passphrase stored separately. Restoring an older configuration can restore old credentials; repeat the review after any restore. Publisher release-signing and PBX-local module-signing keys are separate from these configuration credentials.
