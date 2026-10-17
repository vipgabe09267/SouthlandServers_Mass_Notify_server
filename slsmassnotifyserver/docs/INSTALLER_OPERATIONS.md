# Installer diagnostics, recovery and qualification

Activation reloads Apache and each independently qualified PHP-FPM version. This
refreshes their separate opcode caches without changing PHP settings or restarting
Asterisk. Rollback refreshes FPM after restoring the approved files. The local,
read-only API probe reports its compiled SLS version; installation fails clearly
if the serving handler still runs a previous release or its reload fails.

The supported profile is FreePBX 17 on Debian 12, amd64, CPython 3.11 and
Apache, using the standard FreePBX paths and the `asterisk` service account.
Versioned local PHP-FPM sockets are inspected separately from CLI PHP. The
served Apache or FPM process must run as `asterisk`; required process and
cryptographic functions must be enabled in both the served PHP configuration
and its FPM pool overrides. SLS does not change the global web-server user or
grant another web account access to PBX secrets. Other
architectures, relocated layouts and restricted containers require explicit
adapters and qualification; the installer does not guess replacement paths or
disable its trust checks to accommodate them.

The signed voicemail helper is installed and checked with the other protected
phone runtimes. Voicemail itself remains optional: SLS does not require an enabled
mailbox to install, create mailboxes, modify FreePBX voicemail policies or open a
new service. Weather/Lightning and live paging default to voicemail fallback only
for eligible internal mailboxes. Review the independent controls and the existing
mailbox length/capacity policy during acceptance. Installer and manual alert probes
do not leave voicemail.

Native legacy cron cleanup requires a complete successful read. A timeout,
permission error or partial read leaves existing entries untouched and stops
installation with a specific message. Only the exact current-user “no crontab”
result is treated as an empty initial configuration.

## Read-only diagnostics

After installation, run:

```bash
sudo slsconsole doctor
sudo slsconsole doctor --json
```

The doctor reports the detected Asterisk version and the PHP version, handler,
required extensions/functions and service-account check observed through the
local API route. An active collector service is checked separately from its
current authenticated AMI heartbeat. Root inspection verifies the saved release
signature, installed SLS inventory and reviewed Framework/Dashboard inventories;
it reports the release version and artifact/inventory hashes.

The report also includes prerequisites, Apache configuration, requested resource
allocation and the latest recorded installer phase and stage. A saved failure
marker is explicitly historical; a later commit marks it superseded, and current
readiness comes from the live checks. JSON output excludes configuration contents,
credentials, PINs, provider tokens and recovery snapshot contents. The Help page
uses the saved phone/desktop capacities. `slsconsole doctor` checks 25 desktops
and 25 phone contacts. To inspect another allocation, pass explicit limits to
the protected doctor helper:

```bash
sudo /usr/bin/python3 -I \
  /usr/local/bin/sls_mass_notify/sls_install_doctor.py \
  --json --desktop-limit 50 --phone-limit 100
```

Probes have fixed arguments, output limits and deadlines and run concurrently.
HTTP probes connect only to loopback, use the local virtual-host name and never
follow redirects or resolve a public address. The local HTTPS prerequisite probe
does not validate the public certificate chain; it only identifies the served
PHP runtime. The doctor does not install packages, change configuration, start
services, run FreePBX hooks, open a new AMI session or contact notification
providers. A web-process diagnostic cannot inspect root-only trust or recovery
snapshots; those checks explicitly direct the administrator to the CLI.

On a fresh host, use the publisher-authenticated installer's `--inspect` mode
for signed package and stock inventory checks. Inspection may download evidence
into private temporary storage; it does not enroll live trust or activate code.

## Interrupted installations

Protected recovery evidence is stored under
`/var/lib/sls-mass-notify-installer/transaction-<id>`, with root-only directories
and files. The journal is synchronized before protected mutation and at each
recorded stage transition. It retains
the signed candidate, the signed installer, verified bootstrap helpers,
independent stock/previous-release approvals and recovery snapshots across a
reboot. Public module recovery remains on the module filesystem for atomic
renames; the journal records that location.

Discovery and recovery acquire the existing installation locks before examining
unfinished work. A named, empty, root-owned mode 0700 transaction directory left
before its first journal write is safely recorded as abandoned on the next run.
Unknown contents, unsafe ownership/modes and existing incomplete signed evidence
remain preserved for review. Completed transactions cannot start recovery again,
even when their saved installer is from an older release.

TERM, INT and HUP cause an explicit abort. The installer terminates its own
child work while keeping the worker guard until restoration finishes. Recovery
copies are removed only after a recorded commit, verified rollback or a
transaction that did not begin protected mutation. Incomplete recovery retains
its evidence and blocks the next installation.

If the installer reports an interrupted transaction, preserve its directory
and use the exact location printed in its log:

```bash
bash /path/to/publisher-authenticated/install_release.sh --recover \
  '/var/lib/sls-mass-notify-installer/transaction-<id>'
```

Recovery authenticates the saved installer, package and executable helpers
against publisher evidence before using the original restoration sequence.
The journal does not approve live file hashes. It does not restore operational
delivery history over newer records. Failed restoration or newer activity that
prevents safe verification requires administrator review.

An earlier `0.1.6-beta` installer omitted ownership, permission, collector and
coordination stage names from its transaction allowlist. Those copies can exit with code 2 while their
journal remains in `prepared` phase. Recover that retained transaction with
the command above, then rerun the normal corrected installer. A prepared
transaction is not disposable: its signed evidence and any native-permission
snapshot are checked before completion. The supported recovery stage is also
recognized by the earlier helper. Do not edit its allowlist, replace its saved
installer or delete the transaction directory to bypass recovery.

New stage transitions are recorded before replacing the last successful stage.
If recording fails, restoration can still record completion against that
previous stage. Active and pending settings snapshots remain available through
all postconditions and are removed only after durable completion.

An interruption before complete signed evidence was saved, an expired/revoked
release, lost recovery media or unknown modifications can require manual
review. Keep the installer log and snapshots; do not remove the transaction
directory merely to bypass the retry guard. Successfully completed generations
retain small audit markers; older completed markers are bounded separately
from unfinished recovery evidence. If interruption occurs after durable commit
but before cleanup, the next admitted transaction removes only known completed
evidence inside that generation. Unknown files require review; unfinished
recovery sources are never pruned.

## Missing stock Dashboard

An absent Dashboard can be staged from an independently verified official
GPG package before SLS maintenance changes. Its installation hooks run as
`asterisk`. Existing Dashboard files are never replaced by this bootstrap.
Unknown local differences still require reviewed inventories.

The installer discovers the available Dashboard version through FreePBX's
module metadata without invoking `ma listonline`, which can trigger unrelated
automatic updates. For offline installation, select the exact supplied version:

```bash
export SLS_MASS_NOTIFY_BOOTSTRAP_DASHBOARD_VERSION=17.x.x
```

Supply the corresponding official `dashboard-17.x.x.tgz.gpg`. An invalid,
expired or unapproved upstream signature is rejected before staging it.

## Offline support bundles

Prepare this directory on a trusted machine:

```text
bundle/
  install_release.sh
  slsmassnotifyserver-<version>.tgz
  release-manifest.json
  release-manifest.sig
  upstream/
    dashboard-<exact-version>.tgz.gpg
    framework-<exact-version>.tgz.gpg
  assets/
    wheels/    # complete CPython 3.11 / amd64 hash-locked wheelhouse
    voices/    # every pinned voice and voice JSON from the candidate catalogue
  previous/    # required if prior publisher evidence is not already enrolled
    install_release.sh
    slsmassnotifyserver-<previous-version>.tgz
    release-manifest.json
    release-manifest.sig
```

Keep the directory root-owned and protected from other users' writes.
`tools/prepare_offline_install.py /path/to/bundle` verifies release signatures,
artifact hashes, pinned voices, complete compatible wheel resolution and official
stock package signatures. `--print-environment` prints shell-quoted installer
paths for the reviewed bundle. The installer repeats its own verification.

The Piper dependency phase uses `--no-index` with the supplied wheelhouse and
copies voices only after the existing size and SHA-256 checks. It does not fall
back to internet downloads when an explicitly selected offline asset is absent.

The bundle does not replace Debian's package trust. Required Debian packages
must already be installed or available through a separately authenticated APT
mirror. Include the exact installed stock versions rather than the newest
available versions. A bundle index alone is never installation authority.

## Complete FreePBX VM qualification

`tools/qualify_installer_vm.py` and the manually dispatched
`installer-vm-qualification.yml` workflow exercise real FreePBX installations
outside the production PBX. They require an independently reviewed standalone
qcow2 image, a signed offline bundle and explicit disposable-VM opt-in.

The image must contain only a sanitized test PBX, its prerequisites and this
marker:

```text
/etc/sls-qualification-vm:
SLS_DISPOSABLE_QUALIFICATION_ONLY
```

Each scenario uses a new overlay. Guest outbound networking is disabled; SSH
is forwarded only to the controller's loopback interface. The harness checks
the declared fresh/0.1.4-beta/0.1.5-beta/0.1.6-beta baseline, protected settings, installer
completion, reload and doctor results. Scenarios cover installation, inactive
cron, absent Dashboard, TERM/INT/HUP and an abrupt VM power interruption followed
by authenticated recovery and retry. Power interruption tests require a VM;
they are not simulated by editing an EXIT status.

The ordinary isolated fixtures remain valuable for malformed packages,
permission boundaries, split mounts, low space, PHP configuration, idleness,
rollback and retained history. They do not establish full-PBX VM compatibility,
provider delivery, physical endpoint behavior or load certification. Record
actual qualification results before expanding the supported deployment matrix.

## Recognized legacy permission recovery

An older FreePBX chown/startup run can leave `enterprise-worker.lock`, `phone-admission.lock` or `phone-admission.json` publicly readable at mode `0644`. The installer authenticates the previous release before recovering recognized files to `0640`. The same bounded pass checks the active/pending central configuration and its permanent locks when native chown changed their modes. Configuration is decoded with the authenticated crypto helper before any permission changes; ciphertext, keys and saved settings are not rewritten. It validates the directory and open file descriptors, rejects symbolic/hard links, unexpected ownership, oversized or malformed state, and preserves journal bytes and lock inodes. It does not erase admission history or delete locks.

Copied or restored central settings may be owned by root rather than Asterisk, or may lack `mass-notifications.config.lock`. The installer accepts only known root/Asterisk UID and GID combinations at modes `0600`, `0640` or `0644` for the active/pending settings and empty mutex. It authenticates and decodes every present settings file before creating an absent mutex, then normalizes recognized ownership in place. This also permits a signed fresh-install retry containing valid settings. Other runtime files retain their stricter ownership and previous-release approval checks. A new empty private mutex is retained if a later prerequisite fails, so retry uses the same inode. Existing mutexes, configuration bytes and encryption keys are never replaced by this repair.

The authenticated worker keeper holds the configuration mutex until completion or recovery; it validates the current directory and mutex before and after acquisition. Unsafe files are rejected without blocking on FIFOs or adopting hard links. Active editors have a bounded wait. Failure markers identify publisher approval, legacy permission recovery, previous collector recovery or worker/configuration coordination separately. Review the first specific error in the protected installer log. Successful final verification clears the installation marker; manual permission changes alone do not establish a successful installation.

A managed entry in `/etc/asterisk/freepbx_chown.conf` excludes only `/var/lib/asterisk/SLS_Mass_Notifications_Plugin` from recursive permission normalization. Existing unrelated settings remain intact. Conflicting custom SLS permission directives require administrator review. Managed changes have private recovery evidence; concurrent administrator edits are not silently overwritten.

After recovery, the installer verifies the approved collector service and a fresh authenticated heartbeat before acquiring notification-worker guards. It can start an approved, enabled inactive collector; disabled, masked or custom service states require review. Do not delete a ledger, loosen runtime checks or repeatedly restart FreePBX to bypass a failed readiness check. Preserve the specific diagnostic and installer recovery locations instead.

An older collector may have a voicemail fallback pending in a closed phone batch. Upgrade preflight refuses that still-eligible, unexpired fallback before activation; let it finish or expire, then retry. It does not claim or erase the fallback. Current collectors coordinate voicemail submission and recording cleanup with the same worker guards.

## Legacy Python-cache quarantine

Earlier installers can stop with `Previous runtime changed after preflight; no additional files were approved` immediately after reporting `quarantined_caches: 1` or more. They remove recognized legacy Python caches during permission preparation, then incorrectly require those same caches during the next runtime verification.

Current installers recognize completed or partially completed quarantine only when the protected `runtime-cache-recovery/inventory.json` matches the original approval and the saved cache bytes match their recorded hashes. Each check still verifies authenticated source files and rejects unknown runtime files, unsafe recovery entries and changed caches. Quarantined bytecode never receives execution or rollback approval. Completed quarantine evidence is left intact.

Use the publisher-authenticated installer and package from the exact release generation recorded in the retained transaction. Do not substitute either an older or newer installer merely because it is currently published. After that transaction is recovered, use the intended target release for a new upgrade attempt. If the installer reports an unfinished transaction, use the authenticated recovery command in [Interrupted installations](#interrupted-installations) before retrying. Preserve the transaction and installer log; missing or changed quarantine evidence requires review rather than manual cache deletion or new live-file approvals.

## Included offline removal

Removal records its preparation, teardown and verification stages in a private
transaction under `/var/lib/sls-mass-notify-uninstaller`. Failed attempts retain
authenticated local retry helpers and configuration recovery locations. Completion
is recorded after teardown postconditions, not merely after deleting the runtime.
Only recorded workspaces are cleaned; unrelated `/tmp` files remain untouched.

Run `sudo slsconsole uninstall` or the included `/usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh`. Removal does not download packages or require the installed `sls_privileged_install.py`, speech engine or collector to be healthy. It authenticates fixed teardown helpers against the retained publisher generation and checks independently approved Framework/Dashboard inventories. It drains notification workers under private leases before changing services or removing files, and refuses active phone delivery. It preserves configuration, media, history, logs and recovery keys by default. Purging data is a separate destructive option.

Both crontabs are read and checked before SLS entries are removed. Unexpected read/write failures retain private originals and require review; unrelated jobs are preserved. Retry accepts only exact approved installed or uninstall stock variants. An absent SLS directory must also have an independently checked absent module registry entry. Unknown partial file changes remain a review boundary.

If a late failure removed the command itself, the signed uninstaller remains under the active retained generation, at `/var/lib/sls-mass-notify-trust/<generation>/module/bin/sls_mass_notify_uninstall.sh`. Run that retained script with Bash after reviewing the reported recovery location. Do not substitute an arbitrary live inventory. A stopped PBX is not started. Software removal and a failed final PBX reload are reported separately.

## Mail and cron safeguards

SLS requires executable local Postfix `sendmail` and `postqueue` supporting `-j` for email. Missing queue evidence disables SLS email with an explicit reason; the installer does not replace an existing MTA. The queue probe stops at 200 records and has a one-second deadline. A private reservation journal limits all SLS submissions before transport, with stricter system-fault and recovery quotas. Accepted or ambiguous mail is not automatically replayed.

All five SLS cron entries use the fixed `sls_cron_runner.py` job names and end in `>/dev/null 2>&1`. Child output is captured in private `cron-diagnostics/<job>.jsonl` files, bounded to 1 MiB each with a 64 KiB output tail. Excessive output and fixed job timeouts terminate the child group. Interpreter/logging failures cannot become cron email. Existing non-SLS cron and Postfix settings are not changed.

## Beta acknowledgment

The warning appears on each FreePBX administrator's first authenticated page visit after the mandatory wizard or upgrade. Each account must agree separately; later logins do not repeat it while that account's receipt matches the current warning revision. Existing single receipts apply only to their recorded administrator. It cannot be dismissed with a close button, Escape or backdrop click. Its confirmation requires five visible seconds and a server-side five-second session challenge. The receipt is saved in encrypted active/pending configuration without enabling any feature. The Enterprise Labs warning waits until beta acceptance has been saved. This warning does not prevent command-line removal.
