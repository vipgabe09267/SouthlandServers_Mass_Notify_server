#!/usr/bin/env bash
set -euo pipefail

umask 027

if ! command -v readlink >/dev/null 2>&1; then
  printf 'The installer requires coreutils readlink before bootstrap. Install coreutils, then retry. No PBX state was changed.\n' >&2
  exit 2
fi
INSTALLER_SOURCE_PATH="$(readlink -f -- "${BASH_SOURCE[0]}")"
REQUESTED_RECOVERY=""
if [ "${1:-}" = '--recover' ]; then
  REQUESTED_RECOVERY="${2:-}"
  [ -n "$REQUESTED_RECOVERY" ] || { printf 'Usage: %s --recover /var/lib/sls-mass-notify-installer/transaction-ID\n' "$0" >&2; exit 2; }
  shift 2
fi
if [ "${1:-}" = '--inspect' ]; then
  SLS_MASS_NOTIFY_INVENTORY_ONLY=1
  shift
fi

MODULE="slsmassnotifyserver"
if [ -n "${SLS_MASS_NOTIFY_MODULE:-}" ] \
  && [ "${SLS_MASS_NOTIFY_MODULE}" != "$MODULE" ]; then
  printf '%s\n' \
    "SLS_MASS_NOTIFY_MODULE is fixed to the FreePBX raw module name '$MODULE'; refusing an alternate value." >&2
  exit 2
fi
TGZ="${SLS_MASS_NOTIFY_TGZ:-/tmp/slsmassnotifyserver-0.1.6-beta.tgz}"
URL="${SLS_MASS_NOTIFY_TGZ_URL:-${1:-}}"
SHA256="${SLS_MASS_NOTIFY_SHA256:-}"
TOKEN="${SLS_MASS_NOTIFY_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
LOG_FILE="${SLS_MASS_NOTIFY_INSTALL_LOG:-/tmp/slsmassnotifyserver-install.log}"
EXPECTED_TGZ_SHA256="729948df8fbc54e5dae01c9b26cd714dc86ee11e42671a9a2fdfef0a07f159ca"
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
CONFIG_FILE="$DATA_DIR/mass-notifications.config"
CONFIG_SNAPSHOT=""
CONFIG_HASH_BEFORE=""
FRESH_CONFIG_INITIALIZED=0
FRESH_PHONE_CAPACITY=25
PENDING_CONFIG_STATE=""
PENDING_CONFIG_PATH=""
PENDING_CONFIG_SNAPSHOT=""
PENDING_CONFIG_HASH=""
CONFIG_LOCK_FD=""
WORKER_LOCK_RESPONSE_FD=""
SETTINGS_LOCK="$DATA_DIR/mass-notifications.config.lock"
STAGING_DIR=""
INSTALL_TEMP_ROOT="/tmp"
DOWNLOAD_DIR=""
MODULE_BACKUP_DIR=""
ROLLBACK_FAILED=0
ROLLBACK_ATTEMPTED=0
ROLLBACK_VERIFIED=0
MODULE_ACTIVATED=0
INSTALL_COMMITTED=0
HAD_EXISTING_MODULE=0
MODULE_WAS_ENABLED=0
APT_METADATA_REFRESHED=0
ASTERISK_PACKAGE_REPAIR_ATTEMPTS=""
REPAIR_ASTERISK_PACKAGES="${SLS_MASS_NOTIFY_REPAIR_ASTERISK_PACKAGES:-1}"
INSTALL_MAINTENANCE_LOCK_FD=""
FREEPBX_CONFIRMED=0
REQUESTED_SYSTEM_TIMEZONE="${SLS_MASS_NOTIFY_TIMEZONE:-}"
ORIGINAL_SYSTEM_TIMEZONE=""
SYSTEM_TIMEZONE_CHANGED=0
CONFIRMED_SYSTEM_TIMEZONE=""
# Regression tests source this installer so they can exercise its protected
# filesystem helpers.  Keep their fixture failure markers from creating or
# deleting real FreePBX Dashboard notifications on the host running the test.
INSTALL_NOTIFICATION_SIDE_EFFECTS=1
INSTALL_STAGE="initialization"
INSTALL_SOLUTION="Review /tmp/slsmassnotifyserver-install.log, correct the reported prerequisite, then run the same installer again."
INSTALL_FAILURE_FILE="$DATA_DIR/install-failure.json"
INSTALL_ERROR_CATEGORY="install_command_failed"
INSTALL_EXIT_CODE=1
PRIVILEGED_INSTALL_BOOTSTRAP=""
TRUST_TOOL=""
TRUST_ROOT="/var/lib/sls-mass-notify-trust"
INSTALL_BOOTSTRAP_DIR=""
OFFLINE_ASSET_DIR="$(dirname "$TGZ")"
WORKER_GUARD_TOOL=""
IDLE_TOOL=""
RECOVERY_TOOL=""
RECOVERY_DIR=""
STATIC_MUTATION_STARTED=0
UPGRADE_TRUST_DIR=""
AUTHENTICATED_INSTALLER_APPROVED=0
LEGACY_PERMISSION_TOOL=""
FREEPBX_CHOWN_CONFIG="/etc/asterisk/freepbx_chown.conf"
INSTALL_TRANSACTION_ROOT="/var/lib/sls-mass-notify-installer"
INSTALL_TRANSACTION_DIR=""
TRANSACTION_TOOL=""
INSTALL_SIGNAL=""
RECOVERY_REQUEST_MODE=0
SERVED_PHP_PROFILES=""

# A successful child exit does not turn an interrupted shell into a successful
# installation. Recovery is coordinated by the parent, before locks are released.
handle_install_signal() {
  INSTALL_SIGNAL="$1"
  trap '' TERM INT HUP
  log "Installation interrupted by $1; preserving evidence and restoring the approved previous state."
  terminate_install_children
  exit "$2"
}

terminate_install_children() {
  /usr/bin/python3 -I - "$$" "${WORKER_LOCK_PID:-}" <<'PYSIGNAL' || true
import os, signal, sys, time
parent = int(sys.argv[1]); protected = int(sys.argv[2] or 0)
def children(pid):
    try:
        return [int(item) for item in open('/proc/' + str(pid) + '/task/' + str(pid) + '/children').read().split()]
    except (OSError, ValueError): return []
def descendants(pid):
    result = []
    for child in children(pid):
        if child in (protected, os.getpid()): continue
        result.extend(descendants(child)); result.append(child)
    return result
owned = descendants(parent)
for pid in owned:
    try: os.kill(pid, signal.SIGTERM)
    except ProcessLookupError: pass
deadline = time.monotonic() + 2
while time.monotonic() < deadline and any(os.path.exists('/proc/' + str(pid)) for pid in owned): time.sleep(.05)
# Re-check ancestry: never kill a recycled PID or an unrelated PBX service.
remaining = set(descendants(parent))
for pid in owned:
    if pid in remaining:
        try: os.kill(pid, signal.SIGKILL)
        except ProcessLookupError: pass
PYSIGNAL
}

check_interrupted_installation() {
  /usr/bin/python3 -I - "$INSTALL_TRANSACTION_ROOT" <<'PYDISCOVER'
import json, os, pathlib, stat, sys
root = pathlib.Path(sys.argv[1])
if not root.exists() and not root.is_symlink(): raise SystemExit(0)
fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
try:
    for part in root.parts[1:]:
        child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        os.close(fd); fd = child
        info = os.fstat(fd)
        if info.st_uid != 0 or info.st_mode & 0o022: raise RuntimeError('Unsafe installer evidence directory')
    if os.fstat(fd).st_mode & 0o077: raise RuntimeError('Installer evidence directory must be root-only')
    names = os.listdir(fd)
    if len(names) > 100: raise RuntimeError('Installer evidence needs administrator archival')
    for name in names:
        child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        try:
            info = os.fstat(child)
            if info.st_uid != 0 or info.st_mode & 0o077: raise RuntimeError('Unsafe transaction evidence')
            item = os.open('transaction.json', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=child)
            with os.fdopen(item, 'rb') as stream:
                info = os.fstat(stream.fileno())
                if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or info.st_nlink != 1 or info.st_size > 65536: raise RuntimeError('Unsafe transaction journal')
                value = json.loads(stream.read(65537))
            if value.get('schema') != 1 or value.get('directory') != str(root / name): raise RuntimeError('Invalid transaction journal')
            if value.get('phase') not in ('committed', 'rolled_back', 'abandoned'):
                print('An interrupted SLS installation requires recovery before retrying. Evidence: ' + str(root / name), file=sys.stderr)
                print('Run the publisher-authenticated installer with --recover ' + str(root / name) + '; preserve this directory.', file=sys.stderr)
                raise SystemExit(3)
        finally: os.close(child)
finally: os.close(fd)
PYDISCOVER
}

preflight_profile_identity() {
  /usr/bin/python3 -I - <<'PYPROFILE'
from pathlib import Path
import re, xml.etree.ElementTree as ET
release=Path('/etc/os-release').read_text()
fields=dict(re.findall(r'^([A-Z_]+)=["\']?([^\n"\']*)',release,re.M))
if fields.get('ID')!='debian' or fields.get('VERSION_ID')!='12':
    raise SystemExit('This release qualifies FreePBX 17 on Debian 12. A different operating system requires an explicit tested platform adapter; no package repair was started.')
path=Path('/var/www/html/admin/modules/framework/module.xml')
if path.is_symlink() or not path.is_file() or path.stat().st_size>1048576:
    raise SystemExit('A bounded standard-path Framework module identity is required before package repair.')
body=path.read_bytes()
if b'<!DOCTYPE' in body.upper() or b'<!ENTITY' in body.upper(): raise SystemExit('Unsupported Framework XML declarations.')
xml=ET.fromstring(body)
if xml.findtext('rawname')!='framework' or not xml.findtext('version','').startswith('17.'):
    raise SystemExit('The installer requires FreePBX Framework 17 before package repair.')
PYPROFILE
}

persist_install_transaction() {
  [ -n "$INSTALL_TRANSACTION_DIR" ] || return 0
  local phase="$1" field
  local -a fields=(RECOVERY_DIR INSTALL_BOOTSTRAP_DIR UPGRADE_TRUST_DIR CONFIG_SNAPSHOT CONFIG_HASH_BEFORE PENDING_CONFIG_STATE PENDING_CONFIG_PATH PENDING_CONFIG_SNAPSHOT PENDING_CONFIG_HASH MODULE_BACKUP_DIR STAGING_DIR HAD_EXISTING_MODULE MODULE_WAS_ENABLED MODULE_ACTIVATED STATIC_MUTATION_STARTED ORIGINAL_SYSTEM_TIMEZONE SYSTEM_TIMEZONE_CHANGED)
  local -a values=()
  for field in "${fields[@]}"; do values+=("$field" "${!field}"); done
  /usr/bin/python3 -I - "${values[@]}" <<'PYJOURNAL' | /usr/bin/python3 -I "$TRANSACTION_TOOL" --root "$INSTALL_TRANSACTION_ROOT" --transaction "$INSTALL_TRANSACTION_DIR" --phase "$phase" --stage "$INSTALL_STAGE" update
import json, sys
print(json.dumps(dict(zip(sys.argv[1::2], sys.argv[2::2]))))
PYJOURNAL
}

begin_install_transaction() {
  TRANSACTION_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_transaction.py"
  INSTALL_TRANSACTION_DIR="$(/usr/bin/python3 -I "$TRANSACTION_TOOL" --root "$INSTALL_TRANSACTION_ROOT" begin)" || return 1
  # Retain authenticated executable sources, approvals and settings across a
  # reboot. Candidate downloads and general extraction workspace remain temporary.
  copy_private_release_asset "$INSTALLER_SOURCE_PATH" "$INSTALL_TRANSACTION_DIR/installer.sh" 2097152 || return 1
  copy_private_release_asset "$TGZ" "$INSTALL_TRANSACTION_DIR/package.tgz" 54525952 || return 1
  copy_private_release_asset "$DOWNLOAD_DIR/release-manifest.json" "$INSTALL_TRANSACTION_DIR/release-manifest.json" 16384 || return 1
  copy_private_release_asset "$DOWNLOAD_DIR/release-manifest.sig" "$INSTALL_TRANSACTION_DIR/release-manifest.sig" 64 || return 1
  mv -- "$INSTALL_BOOTSTRAP_DIR" "$INSTALL_TRANSACTION_DIR/bootstrap" || return 1
  INSTALL_BOOTSTRAP_DIR="$INSTALL_TRANSACTION_DIR/bootstrap"
  TRANSACTION_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_transaction.py"
  WORKER_GUARD_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_guard.py"; IDLE_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_idle.py"
  RECOVERY_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_installer_recovery.py"; TRUST_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_module_trust.py"
  PRIVILEGED_INSTALL_BOOTSTRAP="$INSTALL_BOOTSTRAP_DIR/sls_privileged_install.py"
  LEGACY_PERMISSION_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_legacy_permissions.py"
  mv -- "$UPGRADE_TRUST_DIR" "$INSTALL_TRANSACTION_DIR/approval" || return 1
  UPGRADE_TRUST_DIR="$INSTALL_TRANSACTION_DIR/approval"
  retain_configuration_snapshots || return 1
  persist_install_transaction prepared || return 1
  log "Persistent installer recovery evidence: $INSTALL_TRANSACTION_DIR"
}

retain_configuration_snapshots() {
  if [ -n "$CONFIG_SNAPSHOT" ]; then mv -- "$CONFIG_SNAPSHOT" "$INSTALL_TRANSACTION_DIR/config.snapshot" || return 1; CONFIG_SNAPSHOT="$INSTALL_TRANSACTION_DIR/config.snapshot"; fi
  if [ -n "$PENDING_CONFIG_SNAPSHOT" ]; then mv -- "$PENDING_CONFIG_SNAPSHOT" "$INSTALL_TRANSACTION_DIR/pending.snapshot" || return 1; PENDING_CONFIG_SNAPSHOT="$INSTALL_TRANSACTION_DIR/pending.snapshot"; fi
}

recover_interrupted_installation() {
  local requested="$1" field i
  # Verify saved programs before executing any of them. The durable journal is
  # a locator, not a new signing authority or a baseline of live installed files.
  /usr/bin/python3 -I - "$INSTALL_TRANSACTION_ROOT" "$requested" <<'PYRECOVERYAUTH' || return 1
import hashlib, json, os, pathlib, re, stat, subprocess, sys, tarfile, tempfile, types
root, path = map(pathlib.Path, sys.argv[1:])
if path.parent != root or not re.fullmatch(r'transaction-[0-9a-f]{24}', path.name): raise SystemExit('Use the exact protected transaction directory printed in the installer log.')
def read(item, limit):
    for parent in reversed(item.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022: raise RuntimeError('Unprotected recovery evidence parent')
    fd = os.open(item, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1 or info.st_size > limit: raise RuntimeError('Unsafe recovery evidence')
        body = stream.read(limit + 1)
        after = os.fstat(stream.fileno())
        if len(body) != info.st_size or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns): raise RuntimeError('Recovery evidence changed')
        return body
manifest_body = read(path / 'release-manifest.json', 16384)
signature = read(path / 'release-manifest.sig', 64)
verifier = pathlib.Path('/usr/local/bin/sls_mass_notify/sls_release_trust.py')
if verifier.exists() or verifier.is_symlink():
    module = types.ModuleType('protected_recovery_publisher'); module.__file__ = str(verifier)
    exec(compile(read(verifier, 262144), str(verifier), 'exec'), module.__dict__)
    manifest = module.authenticate_manifest(manifest_body, signature)
else:
    if any((pathlib.Path('/var/lib/sls-mass-notify-trust') / name).exists() for name in ('publisher-trust.json', 'release-trust.initialized')):
        raise RuntimeError('Restore the protected publisher verifier before recovery')
    with tempfile.TemporaryDirectory(prefix='sls-recovery-signature-') as name:
        scratch = pathlib.Path(name)
        (scratch / 'pub').write_bytes(b'-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=\n-----END PUBLIC KEY-----\n')
        (scratch / 'body').write_bytes(manifest_body); (scratch / 'sig').write_bytes(signature)
        subprocess.run(['/usr/bin/openssl','pkeyutl','-verify','-rawin','-pubin','-inkey',str(scratch / 'pub'),'-in',str(scratch / 'body'),'-sigfile',str(scratch / 'sig')], check=True, timeout=10, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    manifest = json.loads(manifest_body)
for name, field, limit in (('installer.sh','installer_sha256',2097152),('package.tgz','package_sha256',54525952)):
    if hashlib.sha256(read(path / name, limit)).hexdigest() != manifest.get(field): raise RuntimeError('Recovery bytes do not match the signed release: ' + name)
with tarfile.open(path / 'package.tgz', 'r:gz') as archive:
    for helper in (path / 'bootstrap').iterdir():
        member = archive.getmember('slsmassnotifyserver/bin/sls_mass_notify/' + helper.name)
        if not member.isfile() or member.size > 2097152: raise RuntimeError('Unsafe authenticated recovery helper')
        if read(helper,2097152) != archive.extractfile(member).read(2097153): raise RuntimeError('Recovery helper does not match its signed package')
print('Recovery programs authenticated. No live hashes were approved.')
PYRECOVERYAUTH
  if [ "$INSTALLER_SOURCE_PATH" != "$requested/installer.sh" ]; then
    exec /usr/bin/bash "$requested/installer.sh" --recover "$requested"
  fi
  INSTALL_TRANSACTION_DIR="$requested"
  RECOVERY_REQUEST_MODE=1
  TRANSACTION_TOOL="$requested/bootstrap/sls_install_transaction.py"
  local -a fields=(RECOVERY_DIR INSTALL_BOOTSTRAP_DIR UPGRADE_TRUST_DIR CONFIG_SNAPSHOT CONFIG_HASH_BEFORE PENDING_CONFIG_STATE PENDING_CONFIG_PATH PENDING_CONFIG_SNAPSHOT PENDING_CONFIG_HASH MODULE_BACKUP_DIR STAGING_DIR HAD_EXISTING_MODULE MODULE_WAS_ENABLED MODULE_ACTIVATED STATIC_MUTATION_STARTED ORIGINAL_SYSTEM_TIMEZONE SYSTEM_TIMEZONE_CHANGED)
  local -a values=()
  local recovered_values
  recovered_values="$(/usr/bin/python3 -I "$TRANSACTION_TOOL" --root "$INSTALL_TRANSACTION_ROOT" --transaction "$requested" values)" || return 1
  mapfile -t values <<<"$recovered_values"
  [ "${#values[@]}" -eq "${#fields[@]}" ] || { log 'The recovery settings are incomplete; evidence remains protected.'; return 1; }
  for i in "${!fields[@]}"; do printf -v "${fields[$i]}" '%s' "${values[$i]}"; done
  RECOVERY_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_installer_recovery.py"; IDLE_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_idle.py"
  WORKER_GUARD_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_guard.py"
  LEGACY_PERMISSION_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_legacy_permissions.py"
  acquire_update_coordination || return 1
  acquire_maintenance_coordination || return 1
  INSTALL_LOG_FD=""; open_root_owned_file INSTALL_LOG_FD "$LOG_FILE" log || return 1
  INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$INSTALL_LOG_FD"
  # Signed recovery programs and their retained approvals were authenticated
  # above. Tighten the same recognized legacy metadata before acquiring a
  # guard that deliberately rejects permissive old modes.
  run_logged /usr/bin/python3 -I "$LEGACY_PERMISSION_TOOL" --data "$DATA_DIR" \
    --approval "$UPGRADE_TRUST_DIR/previous-root-approval.json" repair || return 1
  acquire_worker_coordination || return 1
  acquire_settings_coordination || { release_worker_coordination; return 1; }
  set_install_stage 'interrupted installation recovery' 'Preserve persistent transaction evidence and correct the specific failed restoration check before retrying recovery.'
  trap guard_config_on_exit EXIT
  trap 'handle_install_signal TERM 143' TERM
  trap 'handle_install_signal INT 130' INT
  trap 'handle_install_signal HUP 129' HUP
  # Reuse the original approved restoration, configuration and verification
  # sequence. An uncommitted static transaction always rolls back on EXIT.
  exit 1
}

# PHP/FreePBX code is maintained by the web account. It must never become a
# root program merely because this installer is root. Privileged operations
# use the authenticated fixed-operation helper below, not a PHP module hook.
sls_as_asterisk() {
  local uid
  uid="$(id -u asterisk 2>/dev/null)" || { log 'The asterisk service account is missing.'; return 1; }
  [[ "$uid" =~ ^[0-9]+$ ]] && [ "$uid" -ne 0 ] || { log 'The asterisk service account must have a nonzero UID.'; return 1; }
  run_without_install_maintenance_lock /usr/sbin/runuser -u asterisk -- "$@"
}

php() { sls_as_asterisk /usr/bin/php "$@"; }

fwconsole() {
  case "${1:-}" in
    chown|start|stop|restart)
      log "Root-dependent fwconsole action '$1' is not permitted inside this installer. Use the fixed protected service/permission phases."
      return 1 ;;
  esac
  sls_as_asterisk /usr/sbin/fwconsole "$@"
}

protected_install_phase() {
  [ -n "$PRIVILEGED_INSTALL_BOOTSTRAP" ] && [ -f "$PRIVILEGED_INSTALL_BOOTSTRAP" ] || {
    log 'The authenticated privileged installer has not been prepared. No root module hook will be used as a fallback.'
    return 1
  }
  local -a offline=()
  if [ "${1:-}" = dependencies ] && [ -n "${SLS_MASS_NOTIFY_OFFLINE_ASSETS:-}" ]; then offline+=(--offline-assets "$SLS_MASS_NOTIFY_OFFLINE_ASSETS"); fi
  run_logged /usr/bin/python3 -I "$PRIVILEGED_INSTALL_BOOTSTRAP" --trust-root "$TRUST_ROOT" "$@" "${offline[@]}"
}

initialize_configuration_keyring() {
  /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_config_crypto.py" init >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

prepare_installer_bootstrap() {
  local module_name
  INSTALL_BOOTSTRAP_DIR="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-bootstrap.XXXXXX")" || return 1
  chmod 0700 "$INSTALL_BOOTSTRAP_DIR" || return 1
  for module_name in sls_module_trust.py sls_release_trust.py sls_privileged_install.py sls_installer_recovery.py sls_install_transaction.py sls_install_idle.py sls_install_guard.py sls_audio_state.py sls_config_crypto.py sls_upgrade_trust.py sls_legacy_permissions.py; do
    install -o root -g root -m 0700 "$STAGING_DIR/$MODULE/bin/sls_mass_notify/$module_name" "$INSTALL_BOOTSTRAP_DIR/$module_name" || return 1
  done
  install -o root -g root -m 0600 "$STAGING_DIR/$MODULE/bin/sls_mass_notify/freepbx-mirror-signing.pub" "$INSTALL_BOOTSTRAP_DIR/freepbx-mirror-signing.pub" || return 1
  export SLS_CONFIG_CRYPTO_PHP="$STAGING_DIR/$MODULE/api/sls-mass-notify/config-crypto.php"
  WORKER_GUARD_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_guard.py"
  IDLE_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_idle.py"
  RECOVERY_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_installer_recovery.py"
  TRUST_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_module_trust.py"
  PRIVILEGED_INSTALL_BOOTSTRAP="$INSTALL_BOOTSTRAP_DIR/sls_privileged_install.py"
  LEGACY_PERMISSION_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_legacy_permissions.py"
  # Bootstrap helpers are private; keyring initialization follows approval.
  # Public code, user settings, trust pointers and root schedules are unchanged.
}

prepare_authenticated_installer() {
  local manifest signature module_name inventory digest
  [ -n "$PRIVILEGED_INSTALL_BOOTSTRAP" ] && [ -f "$PRIVILEGED_INSTALL_BOOTSTRAP" ] || return 1
  manifest="$DOWNLOAD_DIR/release-manifest.json"
  signature="$DOWNLOAD_DIR/release-manifest.sig"
  [ -f "$manifest" ] && [ ! -L "$manifest" ] && [ -f "$signature" ] && [ ! -L "$signature" ] || {
    log 'The release manifest and Ed25519 signature are required for protected installation, including offline candidates. Supply SLS_MASS_NOTIFY_MANIFEST and SLS_MASS_NOTIFY_MANIFEST_SIGNATURE alongside the archive.'
    return 1
  }
  UPGRADE_TRUST_DIR="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-upgrade-review.XXXXXX")" || return 1
  chmod 0700 "$UPGRADE_TRUST_DIR" || return 1
  log "Upgrade trust and review evidence: $UPGRADE_TRUST_DIR"
  run_logged /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" \
    --workspace "$UPGRADE_TRUST_DIR" --trust-root "$TRUST_ROOT" \
    --archive "$TGZ" --manifest "$manifest" --signature "$signature" || return 1
  # This only loads already approved protected inventories. It does not learn
  # expected bytes from the installed Dashboard or Framework tree.
  run_logged /usr/bin/python3 -I - "$TRUST_TOOL" "$TRUST_ROOT" "$STAGING_DIR/$MODULE" "$UPGRADE_TRUST_DIR/approvals.json" <<'PY' || return 1
from pathlib import Path
import sys
import types
sys.dont_write_bytecode = True
path = Path(sys.argv[1])
trust = types.ModuleType('sls_installer_trust'); trust.__file__ = str(path)
exec(compile(path.read_bytes(), str(path), 'exec'), trust.__dict__)
# Inventory every stock module before prepare/dependencies. Only the two
# publisher-authenticated Dashboard overlays and the explicitly reviewed menu
# transition may differ; no live hashes become new approval evidence.
import os, stat
candidate = Path(sys.argv[3])
web = Path('/var/www/html')
import json
approvals = json.loads(trust.read(Path(sys.argv[4]), trust.MAX_MANIFEST * 4, protected=True))
prior = approvals['prior']
for module in ('dashboard', 'framework'):
    data = approvals['approved'][module]
    overlays = {
        'sections/SlsMassNotifyAnnouncement.class.php': candidate / 'dashboard/sections/SlsMassNotifyAnnouncement.class.php',
        'views/sections/sls-mass-notify-announcement.php': candidate / 'dashboard/views/sections/sls-mass-notify-announcement.php',
    } if module == 'dashboard' else {}
    for name, path in overlays.items():
        if name not in data['files'] or trust.digest(trust.read(path, trust.MAX_ARCHIVE)) != data['files'][name]['sha256']:
            raise SystemExit('Reviewed Dashboard integration does not match this signed candidate: ' + name)
    actual = trust.inventory_tree(web / 'admin/modules' / module, module)
    expected = {name for name, entry in data['files'].items() if entry['target'].startswith('admin/modules/' + module + '/')}
    if actual - set(overlays) != expected - set(overlays):
        raise SystemExit('Installed ' + module + ' file inventory is not covered by independent approval')
    total = 0
    for name, entry in data['files'].items():
        if name in overlays:
            if name not in actual: continue
            observed = trust.digest(trust.read(web / entry['target'], trust.MAX_ARCHIVE))
            previous = prior.get(module, {}).get('files', {}).get(name, {}).get('sha256')
            if observed not in {entry['sha256'], previous}:
                raise SystemExit('Existing SLS Dashboard overlay lacks prior approval: ' + name)
            continue
        if 'link_target' in entry:
            path = web / entry['target']
            parent = trust.directory(path.parent)
            try:
                before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                target = str(web / entry['link_target'])
                if not stat.S_ISLNK(before.st_mode) or before.st_nlink != 1 or os.readlink(path.name, dir_fd=parent) != target:
                    raise SystemExit('Reviewed Framework asset link changed')
                body = trust.read(Path(target), trust.MAX_ARCHIVE)
                after = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                if (before.st_dev, before.st_ino, before.st_ctime_ns) != (after.st_dev, after.st_ino, after.st_ctime_ns):
                    raise SystemExit('Reviewed Framework asset link changed during inspection')
            finally: os.close(parent)
        else:
            body = trust.read(web / entry['target'], trust.MAX_ARCHIVE)
        total += len(body)
        if total > 1024 * 1024 * 1024: raise SystemExit('Stock inventory exceeds verification size bound')
        allowed = {entry['sha256']}
        if module == 'framework' and name == 'amp_conf/htdocs/admin/views/menu_items.php':
            previous = data.get('uninstall', {}).get('replace', {}).get(name)
            if previous: allowed.add(previous)
            previous_overlay = prior.get(module, {}).get('files', {}).get(name, {}).get('sha256')
            if previous_overlay: allowed.add(previous_overlay)
        if trust.digest(body) not in allowed:
            raise SystemExit('Stock file differs from independently approved inventory: ' + module + '/' + name)
PY
  AUTHENTICATED_INSTALLER_APPROVED=1
  if [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" = 1 ]; then
    preflight_authenticated_prerequisites || return 1
    log "Inventory preflight passed. Review $UPGRADE_TRUST_DIR; no module, trust pointers or maintenance schedules were changed."
    return 0
  fi
}

preflight_authenticated_prerequisites() {
  run_logged /usr/bin/python3 -I "$PRIVILEGED_INSTALL_BOOTSTRAP" --trust-root "$UPGRADE_TRUST_DIR/registry" preflight || return 1
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$UPGRADE_TRUST_DIR/service-preview" preflight-services || return 1
}

repair_authenticated_legacy_permissions() {
  [ "$AUTHENTICATED_INSTALLER_APPROVED" -eq 1 ] && [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" != 1 ] || {
    log 'Legacy permission recovery requires approved previous and candidate releases; inspection cannot perform repairs.'
    return 1
  }
  # Restore only missing signed prior helpers and retire verified generated
  # caches before checking the previous collector. Existing executable bytes
  # have already been matched against the publisher-authenticated release.
  run_logged /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" \
    --workspace "$UPGRADE_TRUST_DIR" --normalize-runtime || return 1
  run_logged /usr/bin/python3 -I "$LEGACY_PERMISSION_TOOL" --data "$DATA_DIR" \
    --approval "$UPGRADE_TRUST_DIR/previous-root-approval.json" repair
}

protect_freepbx_chown_permissions() {
  [ "$AUTHENTICATED_INSTALLER_APPROVED" -eq 1 ] && [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" != 1 ] || return 1
  run_logged /usr/bin/python3 -I "$LEGACY_PERMISSION_TOOL" --data "$DATA_DIR" \
    --approval "$UPGRADE_TRUST_DIR/previous-root-approval.json" \
    --chown-config "$FREEPBX_CHOWN_CONFIG" --snapshot "$UPGRADE_TRUST_DIR/freepbx-chown.snapshot" protect-chown
}

restore_freepbx_chown_permissions() {
  [ -n "$UPGRADE_TRUST_DIR" ] || return 0
  local snapshot="$UPGRADE_TRUST_DIR/freepbx-chown.snapshot"
  [ -e "$snapshot" ] || [ -L "$snapshot" ] || return 0
  run_logged /usr/bin/python3 -I "$LEGACY_PERMISSION_TOOL" --data "$DATA_DIR" \
    --chown-config "$FREEPBX_CHOWN_CONFIG" --snapshot "$snapshot" restore-chown
}

restore_previous_phone_collector() {
  [ "$AUTHENTICATED_INSTALLER_APPROVED" -eq 1 ] && [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" != 1 ] || return 1
  local requirement state loaded active
  requirement="$(/usr/bin/python3 -I - "$UPGRADE_TRUST_DIR/previous-root-approval.json" <<'PYCOLLECTORAPPROVAL'
import json, pathlib, sys
approval = json.loads(pathlib.Path(sys.argv[1]).read_bytes())
if approval.get('source') == 'fresh-install' and approval.get('files') == {}:
    print('absent')
elif approval.get('source') == 'publisher-verified-previous-release' and isinstance(approval.get('files'), dict):
    names = approval['files']
    required = ['/usr/local/bin/sls_mass_notify/sls_phone_events.py', '/usr/local/bin/sls_mass_notify/sls_phone_admission.py']
    present = [name in names for name in required]
    if any(present) and not all(present): raise SystemExit('Previous phone runtime approval is incomplete')
    print('required' if all(present) else 'absent')
else:
    raise SystemExit('Previous release approval is missing or invalid')
PYCOLLECTORAPPROVAL
)" || return 1
  [ "$requirement" = required ] || return 0
  # This verifies the exact fixed unit and rejects masks and administrator
  # drop-ins before any service operation. Disabled services stay disabled.
  state="$(/usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$UPGRADE_TRUST_DIR/service-preview" preflight-services)" || return 1
  active="$(/usr/bin/python3 -I - "$state" <<'PYCOLLECTORSTATE'
import json, sys
value = json.loads(sys.argv[1])
state = value.get('service_state', {})
if value.get('ok') is not True or state.get('enabled') != 'enabled' or state.get('active') not in ('active', 'inactive', 'failed'):
    raise SystemExit('The approved previous phone collector must be enabled. Restore its supported service before upgrading; administrator-disabled or missing services are not enabled automatically.')
print(state['active'])
PYCOLLECTORSTATE
)" || return 1
  loaded="$(systemctl show sls-mass-notify-phone-events.service --property=User,Group,FragmentPath,DropInPaths)" || return 1
  /usr/bin/python3 -I - "$loaded" <<'PYCOLLECTORIDENTITY' || return 1
import sys
values = dict(line.split('=', 1) for line in sys.argv[1].splitlines() if '=' in line)
if values != {'User':'asterisk', 'Group':'asterisk', 'FragmentPath':'/etc/systemd/system/sls-mass-notify-phone-events.service', 'DropInPaths':''}:
    raise SystemExit('The loaded previous phone collector does not match its approved unprivileged service. Review systemd configuration before upgrading.')
PYCOLLECTORIDENTITY
  if [ "$active" != active ]; then
    run_logged systemctl start sls-mass-notify-phone-events.service || return 1
  fi
  verify_phone_collector || {
    log 'Previous phone collector recovery failed after permission repair. No candidate runtime or maintenance schedule was activated. Review the collector journal and approved service configuration before retrying.'
    return 1
  }
}

commit_authenticated_installer() {
  local manifest="$DOWNLOAD_DIR/release-manifest.json" signature="$DOWNLOAD_DIR/release-manifest.sig"
  local module_name inventory digest
  # Retire untrusted generated bytecode and restore only absent authenticated
  # prior helpers before the rollback snapshot can authorize prior root jobs.
  run_logged /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" \
    --workspace "$UPGRADE_TRUST_DIR" --normalize-runtime || return 1
  prepare_install_recovery || return 1
  STATIC_MUTATION_STARTED=1
  persist_install_transaction mutating || return 1
  /usr/bin/python3 -I "$TRUST_TOOL" --root "$TRUST_ROOT" enroll-sls \
    --archive "$TGZ" --manifest "$manifest" --signature "$signature" || return 1
  for module_name in dashboard framework; do
    inventory="$UPGRADE_TRUST_DIR/$module_name.json"
    digest="$(sha256sum "$inventory" | cut -d ' ' -f 1)" || return 1
    /usr/bin/python3 -I "$TRUST_TOOL" --root "$TRUST_ROOT" enroll-reviewed --inventory "$inventory" --sha256 "$digest" || return 1
  done
  protected_install_phase plan
}

# The protected log opener requires Python before the ordinary dependency pass.
# Bootstrap only that missing package on an identified Debian 12 FreePBX host;
# no log pathname is opened or written until the safe opener can run.
ensure_installer_log_prerequisites() {
  local python_probe='import os, pwd, stat, sys; assert all(hasattr(os, name) for name in ("O_NOFOLLOW", "O_DIRECTORY", "O_CLOEXEC", "O_NONBLOCK")); assert callable(os.fstat)'
  local platform_id="" platform_version="" platform_key platform_value
  if [ -x /usr/bin/python3 ]; then
    if /usr/bin/python3 -I -c "$python_probe" >/dev/null 2>&1; then
      return 0
    fi
    printf 'The existing /usr/bin/python3 cannot load the standard library required for secure installer logging. Repair the system Python installation, then retry; no binary was replaced.\n' >&2
    return 1
  fi
  if [ -e /usr/bin/python3 ] || [ -L /usr/bin/python3 ]; then
    printf '/usr/bin/python3 exists but is not executable or its link is broken. Repair the system Python installation, then retry; no binary was replaced.\n' >&2
    return 1
  fi
  if [ -r /etc/os-release ]; then
    while IFS='=' read -r platform_key platform_value; do
      platform_value="${platform_value#\"}"
      platform_value="${platform_value%\"}"
      platform_value="${platform_value#\'}"
      platform_value="${platform_value%\'}"
      case "$platform_key" in
        ID) platform_id="$platform_value" ;;
        VERSION_ID) platform_version="$platform_value" ;;
      esac
    done </etc/os-release
  fi
  if [ "$platform_id" != "debian" ] || [ "$platform_version" != "12" ] \
    || [ ! -x /usr/sbin/fwconsole ] || [ ! -x /usr/sbin/asterisk ] \
    || [ ! -r /etc/freepbx.conf ] || [ ! -d /var/www/html/admin/modules ] \
    || [ ! -x /usr/bin/apt-get ]; then
    printf 'Python 3 is required for secure installer logging. Automatic bootstrap requires a recognized Debian 12 FreePBX host and apt-get; no packages were changed.\n' >&2
    return 1
  fi
  printf 'Installing missing Python 3 for secure installer logging. Package-manager output follows on this console.\n'
  if ! DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get update; then
    printf 'Unable to refresh Debian package metadata for the missing Python prerequisite. Correct repository access, then retry.\n' >&2
    return 1
  fi
  APT_METADATA_REFRESHED=1
  if ! DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y --no-install-recommends --no-remove python3; then
    printf 'Unable to install the missing Python prerequisite. Review the package-manager error above, then retry.\n' >&2
    return 1
  fi
  if [ ! -x /usr/bin/python3 ] || ! /usr/bin/python3 -I -c "$python_probe" >/dev/null 2>&1; then
    printf 'Python installation returned but its executable or required standard library is unavailable. Repair the system Python installation before retrying.\n' >&2
    return 1
  fi
}

# Open only a root-owned regular file through trusted directories. Creation is
# exclusive; an existing file is never truncated or written before fd identity
# verification. Sticky root-owned /tmp and /run/lock are permitted. Only log
# callers may adopt a legacy asterisk-owned regular file; locks remain strict.
open_root_owned_file() {
  local output_variable="$1" protected_path="$2" file_purpose="${3:-lock}"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 -I - "$protected_path" "$file_purpose" <<'PY'
import os
import pwd
import stat
import sys

path = sys.argv[1]
purpose = sys.argv[2]
if purpose not in {"lock", "log"}:
    raise SystemExit("Unknown protected file purpose.")
parts = path.split("/")[1:]
if not path.startswith("/") or not parts or any(part in {"", ".", ".."} for part in parts):
    raise SystemExit(1)
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW
parent = os.open("/", directory_flags)
try:
    for component in parts[:-1]:
        child = os.open(component, directory_flags, dir_fd=parent)
        os.close(parent)
        parent = child
        metadata = os.fstat(parent)
        if metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX):
            raise SystemExit(1)
    flags = os.O_WRONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    try:
        descriptor = os.open(parts[-1], flags | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=parent)
    except FileExistsError:
        descriptor = os.open(parts[-1], flags, dir_fd=parent)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise SystemExit("Refusing a linked or non-regular protected file.")
        if purpose == "log":
            allowed_owners = {0}
            try:
                allowed_owners.add(pwd.getpwnam("asterisk").pw_uid)
            except KeyError:
                pass
            if metadata.st_uid not in allowed_owners:
                raise SystemExit("Protected logs must be owned by root or the Asterisk service account.")
            # Old installers/FreePBX chown can leave service-owned logs in
            # sticky /tmp. O_CREAT would fail even as root on Debian. The
            # existing file was opened without it; secure this exact inode
            # before Bash reopens it, without following or replacing links.
            os.fchown(descriptor, 0, 0)
            os.fchmod(descriptor, 0o600)
        elif metadata.st_uid != 0 or metadata.st_mode & 0o022:
            raise SystemExit(1)
        current = os.fstat(descriptor)
        entry = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
        if (current.st_dev, current.st_ino) != (entry.st_dev, entry.st_ino) or current.st_nlink != 1 or current.st_uid != 0 or current.st_mode & 0o022:
            raise SystemExit("Protected file changed while it was being opened.")
        print(f"{metadata.st_dev}:{metadata.st_ino}")
    finally:
        os.close(descriptor)
finally:
    os.close(parent)
PY
)" || return 1
  exec {protected_fd}>>"$protected_path" || return 1
  opened_identity="$(stat -Lc '%d:%i' "/proc/${BASHPID}/fd/$protected_fd" 2>/dev/null)" || opened_identity=""
  if [ "$opened_identity" != "$protected_identity" ]; then
    exec {protected_fd}>&-
    return 1
  fi
  chmod 0600 "/proc/${BASHPID}/fd/$protected_fd" || {
    exec {protected_fd}>&-
    return 1
  }
  printf -v "$output_variable" '%s' "$protected_fd"
}

log() {
  printf '%s\n' "$*"
  if [ -n "${INSTALL_LOG_FD:-}" ]; then
    printf '%s\n' "$*" >&"$INSTALL_LOG_FD"
  fi
}

run_logged() {
  local output status
  if output="$("$@" 2>&1)"; then status=0; else status=$?; fi
  [ -z "$output" ] || log "$output"
  return "$status"
}

set_install_stage() {
  local next_stage="$1" next_solution="$2" status
  if [ -n "$INSTALL_TRANSACTION_DIR" ] && [ -n "$TRANSACTION_TOOL" ]; then
    if /usr/bin/python3 -I "$TRANSACTION_TOOL" --root "$INSTALL_TRANSACTION_ROOT" --transaction "$INSTALL_TRANSACTION_DIR" --stage "$next_stage" stage; then
      :
    else
      status=$?
      INSTALL_ERROR_CATEGORY="installer_stage_recording_failed"
      INSTALL_SOLUTION="The authenticated installer could not record stage '$next_stage'. Preserve the transaction directory and installer log, and use a corrected signed installer before retrying."
      log "$INSTALL_SOLUTION"
      return "$status"
    fi
  fi
  # A rejected transition must not poison rollback's last valid journal stage.
  INSTALL_STAGE="$next_stage"
  INSTALL_SOLUTION="$next_solution"
}

ensure_data_directory() {
  DATA_DIRECTORY_PATH="$DATA_DIR" /usr/bin/python3 -I - <<'PY'
import os
import pwd
import stat

path = os.environ["DATA_DIRECTORY_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path:
    raise SystemExit(2)
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    try:
        directory_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    except FileNotFoundError:
        os.mkdir(parts[-1], 0o750, dir_fd=parent_fd)
        directory_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    try:
        if not stat.S_ISDIR(os.fstat(directory_fd).st_mode):
            raise SystemExit(3)
        account = pwd.getpwnam("asterisk")
        os.fchmod(directory_fd, 0o750)
        os.fchown(directory_fd, account.pw_uid, account.pw_gid)
    finally:
        os.close(directory_fd)
finally:
    os.close(parent_fd)
PY
}

record_install_failure() {
  local marker_tmp
  [ "$FREEPBX_CONFIRMED" -eq 1 ] || return 0
  ensure_data_directory 2>/dev/null || return 0
  marker_tmp="$(mktemp "$INSTALL_TEMP_ROOT/slsmassnotifyserver-install-failure.XXXXXX" 2>/dev/null || true)"
  [ -n "$marker_tmp" ] || return 0
  if ! /usr/bin/php -r '
$payload = [
    "version" => 1,
    "failed_at" => gmdate("c"),
    "stage" => substr(preg_replace("/[^A-Za-z0-9 ._\/-]/", "", (string)$argv[1]), 0, 80),
    "message" => "SLS Mass Notify installation did not complete.",
    "solution" => substr(preg_replace("/[[:cntrl:]]/", " ", (string)$argv[2]), 0, 400),
    "log" => substr(preg_replace("/[[:cntrl:]]/", "", (string)$argv[6]), 0, 500),
    "error_category" => preg_match("/^[a-z][a-z0-9_]{0,63}$/", $argv[4]) ? $argv[4] : "install_command_failed",
    "exit_code" => max(1, min(255, (int)$argv[5])),
];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($argv[3], $json . PHP_EOL, LOCK_EX) === false) {
    exit(1);
}
' "$INSTALL_STAGE" "$INSTALL_SOLUTION" "$marker_tmp" "$INSTALL_ERROR_CATEGORY" "$INSTALL_EXIT_CODE" "$LOG_FILE" 2>/dev/null; then
    rm -f "$marker_tmp"
    return 0
  fi
  safe_config_restore "$marker_tmp" "$INSTALL_FAILURE_FILE" 2>/dev/null || {
    rm -f "$marker_tmp"
    return 0
  }
  rm -f "$marker_tmp"
  [ "${INSTALL_NOTIFICATION_SIDE_EFFECTS:-1}" -eq 1 ] || return 0
  php -r '
require "/etc/freepbx.conf";
$detail = "Stage: " . $argv[1] . ". " . $argv[2] . " Installer log: " . htmlspecialchars($argv[3], ENT_QUOTES, "UTF-8");
\FreePBX::Notifications()->add_error(
    "slsmassnotifyserver",
    "INSTALLFAILED",
    "SLS Mass Notify installation failed",
    $detail,
    "",
    true,
    true
);
exit(0);
' "$INSTALL_STAGE" "$INSTALL_SOLUTION" "$LOG_FILE" >/dev/null 2>&1 || true
}

clear_install_failure() {
  if [ ! -L "$INSTALL_FAILURE_FILE" ]; then
    rm -f "$INSTALL_FAILURE_FILE" 2>/dev/null || true
  fi
  [ "${INSTALL_NOTIFICATION_SIDE_EFFECTS:-1}" -eq 1 ] || return 0
  php -r '
require "/etc/freepbx.conf";
\FreePBX::Notifications()->delete("slsmassnotifyserver", "INSTALLFAILED");
exit(0);
' >/dev/null 2>&1 || true
}

local_web_probe() {
  local path="$1" output="$2" expected_pattern="$3"
  local code target_url target_resolve targets probe_deadline remaining
  probe_deadline=$((SECONDS + 25))
  # Read Apache's parsed vhosts, then connect only to loopback. Public ports,
  # DNS, firewall rules and Apache configuration are never changed by a probe.
  targets="$(php -r '
$helper = "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
if (!is_file($helper)) { exit(0); }
require_once $helper;
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents(
    "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config"
));
foreach (\FreePBX\modules\SlsLocalWebProbe::targets((string)($settings["public_pbx_host"] ?? "")) as $target) {
    echo $target["url"], "\t", $target["resolve"], "\n";
}
' 2>/dev/null || true)"
  if [ -z "$targets" ]; then
    # Previous releases restored by rollback may not contain the new helper.
    targets=$'http://127.0.0.1:80\t127.0.0.1:80:127.0.0.1\nhttps://127.0.0.1:443\t127.0.0.1:443:127.0.0.1'
  fi
  while IFS=$'\t' read -r target_url target_resolve; do
    [ -n "$target_url" ] && [ -n "$target_resolve" ] || continue
    remaining=$((probe_deadline - SECONDS))
    [ "$remaining" -gt 0 ] || break
    [ "$remaining" -le 20 ] || remaining=20
    code="$(curl -ksS --noproxy '*' --connect-timeout 5 --max-time "$remaining" --max-filesize 262144 \
      -o "$output" -w '%{http_code}' --resolve "$target_resolve" "${target_url}${path}" || true)"
    if [[ "$code" =~ $expected_pattern ]]; then
      printf '%s\n' "$code"
      return 0
    fi
  done <<<"$targets"
  printf '%s\n' "${code:-000}"
  return 1
}

rollback_failure() {
  ROLLBACK_FAILED=1
  log "CRITICAL: rollback could not complete: $1. Recovery files will be preserved."
}

remove_phone_collector_service() {
  local unit="/etc/systemd/system/sls-mass-notify-phone-events.service" active_state
  [ -e "$unit" ] || [ -L "$unit" ] || return 0
  python3 -I - "$unit" <<'PY'
import os
import stat
import sys
path = sys.argv[1]
metadata = os.lstat(path)
if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
        or metadata.st_uid != 0 or metadata.st_mode & 0o022):
    raise SystemExit("Refusing to remove an unsafe phone collector service file.")
with open(path, encoding="utf-8") as handle:
    if handle.read(128).splitlines()[:2] != ["# Managed by SLS Mass Notify Server", "[Unit]"]:
        raise SystemExit("Refusing to remove an unrelated phone collector service.")
PY
  [ "$?" -eq 0 ] || return 1
  /usr/bin/timeout --kill-after=2 20 systemctl disable --now sls-mass-notify-phone-events.service || return 1
  active_state="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" || return 1
  case "$active_state" in inactive|failed) ;; *) log "The phone collector has not stopped; its service file was preserved."; return 1 ;; esac
  rm -- "$unit" || return 1
  /usr/bin/timeout --kill-after=2 10 systemctl daemon-reload || return 1
}

repair_restored_api_directory_access() {
  # Legacy installers create root-owned directories under umask027. Repair
  # only the two public API trees after proving they match the restored module.
  python3 -I - "/var/www/html/admin/modules/$MODULE" <<'PY'
import hashlib
import os
import stat
import sys
import time

flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
file_flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
directories = []
entries = 0
total_bytes = 0
started = time.monotonic()

def open_directory(path):
    current = os.open('/', flags)
    try:
        for component in path.split('/')[1:]:
            if component in {'', '.', '..'}:
                raise RuntimeError('invalid API directory')
            following = os.open(component, flags, dir_fd=current)
            os.close(current)
            current = following
        return current
    except BaseException:
        os.close(current)
        raise

def digest(directory, name):
    global total_bytes
    descriptor = os.open(name, file_flags, dir_fd=directory)
    with os.fdopen(descriptor, 'rb') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise RuntimeError('unsafe API file')
        total_bytes += metadata.st_size
        if total_bytes > 32 * 1024 * 1024:
            raise RuntimeError('API source comparison exceeds its limit')
        result = hashlib.sha256()
        read_bytes = 0
        while True:
            if time.monotonic() - started > 5:
                raise RuntimeError('API source comparison exceeds its limit')
            block = handle.read(65536)
            if not block:
                return result.digest()
            read_bytes += len(block)
            if read_bytes > metadata.st_size:
                raise RuntimeError('API file changed during source comparison')
            result.update(block)

def verify(source, target):
    global entries
    names = sorted(os.listdir(source))
    if names != sorted(os.listdir(target)):
        raise RuntimeError('API inventory differs from the restored source')
    directories.append(os.dup(target))
    for name in names:
        entries += 1
        if entries > 512 or time.monotonic() - started > 5:
            raise RuntimeError('API source comparison exceeds its limit')
        source_meta = os.stat(name, dir_fd=source, follow_symlinks=False)
        target_meta = os.stat(name, dir_fd=target, follow_symlinks=False)
        if stat.S_ISDIR(source_meta.st_mode) and stat.S_ISDIR(target_meta.st_mode):
            child_source = os.open(name, flags, dir_fd=source)
            try:
                child_target = os.open(name, flags, dir_fd=target)
                try:
                    verify(child_source, child_target)
                finally:
                    os.close(child_target)
            finally:
                os.close(child_source)
        elif stat.S_ISREG(source_meta.st_mode) and stat.S_ISREG(target_meta.st_mode):
            if digest(source, name) != digest(target, name):
                raise RuntimeError('API file differs from the restored source')
        else:
            raise RuntimeError('unsafe API entry')

try:
    for name, source_path, target_path in (('sipnotify', '/api/sipnotify', '/var/www/html/api/sipnotify'), ('sls-mass-notify', '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify'), ('operator-portal', '/portal', '/var/www/html/mass-notify')):
        try:
            source = open_directory(sys.argv[1] + source_path)
        except FileNotFoundError:
            # Signed releases from before the separate operator portal have
            # no portal tree. Their two API trees still require verification.
            if name == 'operator-portal':
                continue
            raise
        try:
            target = open_directory(target_path)
            try:
                if 'index.php' not in os.listdir(source):
                    raise RuntimeError('restored API source is incomplete')
                verify(source, target)
            finally:
                os.close(target)
        finally:
            os.close(source)
    # Every restored inventory must verify before any permission changes. Held directory
    # descriptors prevent symlink traversal if a pathname changes afterward.
    for descriptor in directories:
        os.fchmod(descriptor, 0o755)
        os.fsync(descriptor)
except (OSError, ValueError, RuntimeError):
    raise SystemExit('Unable to verify and restore public API directory access; recovery files were retained.') from None
finally:
    for descriptor in directories:
        os.close(descriptor)
PY
}

prepare_install_recovery() {
  RECOVERY_DIR="$(mktemp -d "${INSTALL_TRANSACTION_DIR:-$INSTALL_TEMP_ROOT}/sls-mass-notify-recovery.XXXXXX")" || return 1
  chmod 0700 "$RECOVERY_DIR" || return 1
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" preflight-services || return 1
  /usr/bin/python3 -I "$IDLE_TOOL" --data "$DATA_DIR" inspect --repair-collector >"$RECOVERY_DIR/idle-before.json" || return 1
  chmod 0600 "$RECOVERY_DIR/idle-before.json" || return 1
  # Preserve only the module row, eight managed recording identities and
  # this module enrollment in existing backup jobs. Operational state remains live.
  php -r '
require "/etc/freepbx.conf";
$stmt = \FreePBX::Database()->prepare("SELECT * FROM modules WHERE modulename = ?");
$stmt->execute(["slsmassnotifyserver"]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
$filenames = ["custom/SLS_Mass_Notify_Paging_Tone_Opening", "custom/SLS_Mass_Notify_Paging_Tone_Closing", "custom/SLS_Mass_Notify_NWS_Alert", "custom/SLS_Mass_Notify_Lightning_Alert", "custom/Paging_Tone_Opening", "custom/Paging_Tone_Closing", "custom/NWS_alert", "custom/Lightning_alert"];
$recordings = [];
$exists = \FreePBX::Database()->prepare("SHOW TABLES LIKE ?"); $exists->execute(["recordings"]);
$lookup = $exists->fetchColumn() !== false ? \FreePBX::Database()->prepare("SELECT * FROM recordings WHERE filename = ? ORDER BY id") : null;
foreach ($filenames as $filename) { $recordings[$filename] = []; if ($lookup) { $lookup->execute([$filename]); $recordings[$filename] = $lookup->fetchAll(\PDO::FETCH_ASSOC); } if (count($recordings[$filename]) > 100) { throw new \RuntimeException("Too many rows use a reserved SLS recording name"); } }
$backupStatus = \FreePBX::Database()->prepare("SELECT enabled FROM modules WHERE modulename = ?"); $backupStatus->execute(["backup"]);
$backup = $backupStatus->fetchColumn() ? \FreePBX::Create()->Backup : null;
$enrollment = [];
$enabled = static function ($value) { return in_array(strtolower(trim((string)$value)), ["1", "true", "yes", "on"], true); };
foreach (array_keys($backup ? (array)$backup->getAll("backupList") : []) as $job) {
    $job = (string)$job;
    if ($job === "" || strlen($job) > 128 || preg_match("/[\x00-\x1f\x7f]/", $job)) { continue; }
    $selected = (array)$backup->getAll("modules_" . $job);
    if (!array_filter($selected, $enabled) || $enabled($selected["slsmassnotifyserver"] ?? false)) { continue; }
    $enrollment[] = ["job" => $job, "present" => array_key_exists("slsmassnotifyserver", $selected), "value" => $selected["slsmassnotifyserver"] ?? null];
}
$encoded = json_encode(["schema" => 2, "row" => $row ?: null, "recordings" => $recordings, "backup_enrollment" => $enrollment], JSON_THROW_ON_ERROR);
if (strlen($encoded) > 1048576) { throw new \RuntimeException("Scoped installation recovery metadata exceeds 1 MiB; no integration was changed"); }
echo $encoded;
' >"$RECOVERY_DIR/module-registration.json" || return 1
  chmod 0600 "$RECOVERY_DIR/module-registration.json" || return 1
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" --approval "$UPGRADE_TRUST_DIR/previous-root-approval.json" snapshot || return 1
}

verify_install_idle_history() {
  [ -n "${WORKER_LOCK_PID:-}" ] && kill -0 "$WORKER_LOCK_PID" 2>/dev/null || {
    log 'The installer worker guard exited unexpectedly; preserve recovery evidence.'
    return 1
  }
  local -a inspection=(--data "$DATA_DIR" inspect --baseline "$RECOVERY_DIR/idle-before.json")
  if [ "$ROLLBACK_ATTEMPTED" -eq 1 ]; then inspection+=(--repair-collector); fi
  /usr/bin/python3 -I "$IDLE_TOOL" "${inspection[@]}" >/dev/null
}

restore_module_registration() {
  [ -n "$RECOVERY_DIR" ] && [ -f "$RECOVERY_DIR/module-registration.json" ] || return 1
  # Root supplies a read-only stdin descriptor; PHP runs as asterisk and never
  # receives access to the private recovery directory or executable snapshots.
  php -r '
require "/etc/freepbx.conf";
$raw = stream_get_contents(STDIN, 1048577);
if (strlen($raw) > 1048576) { throw new \RuntimeException("Oversized registration snapshot"); }
$data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if (($data["schema"] ?? null) !== 2 || !array_key_exists("row", $data)) { throw new \RuntimeException("Invalid registration snapshot"); }
$row = $data["row"];
if ($row !== null && (!is_array($row) || ($row["modulename"] ?? "") !== "slsmassnotifyserver")) { throw new \RuntimeException("Invalid module registration identity"); }
foreach (($row ?? []) as $column => $value) {
    if (!preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/D", $column) || !(is_scalar($value) || $value === null)) { throw new \RuntimeException("Invalid registration column"); }
}
$filenames = ["custom/SLS_Mass_Notify_Paging_Tone_Opening", "custom/SLS_Mass_Notify_Paging_Tone_Closing", "custom/SLS_Mass_Notify_NWS_Alert", "custom/SLS_Mass_Notify_Lightning_Alert", "custom/Paging_Tone_Opening", "custom/Paging_Tone_Closing", "custom/NWS_alert", "custom/Lightning_alert"];
if (!is_array($data["recordings"] ?? null) || array_keys($data["recordings"]) !== $filenames || !is_array($data["backup_enrollment"] ?? null)) { throw new \RuntimeException("Invalid scoped integration snapshot"); }
$validateRow = static function ($candidate) {
    if (!is_array($candidate) || !$candidate) { throw new \RuntimeException("Invalid recovery row"); }
    foreach ($candidate as $column => $value) {
        if (!preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/D", $column) || !(is_scalar($value) || $value === null)) { throw new \RuntimeException("Invalid recovery column"); }
    }
};
foreach ($data["recordings"] as $filename => $rows) {
    if (!is_array($rows) || count($rows) > 100) { throw new \RuntimeException("Invalid recording recovery rows"); }
    foreach ($rows as $recording) { $validateRow($recording); if (($recording["filename"] ?? "") !== $filename) { throw new \RuntimeException("Recording recovery identity mismatch"); } }
}
foreach ($data["backup_enrollment"] as $entry) {
    if (!is_array($entry) || !is_string($entry["job"] ?? null) || $entry["job"] === "" || strlen($entry["job"]) > 128 || preg_match("/[\x00-\x1f\x7f]/", $entry["job"]) || !is_bool($entry["present"] ?? null) || !(is_scalar($entry["value"] ?? null) || ($entry["value"] ?? null) === null)) { throw new \RuntimeException("Invalid backup enrollment recovery entry"); }
}
$db = \FreePBX::Database();
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM modules WHERE modulename = ?")->execute(["slsmassnotifyserver"]);
    if ($row !== null) {
        $columns = implode(",", array_map(static function ($value) { return "`" . $value . "`"; }, array_keys($row)));
        $marks = implode(",", array_fill(0, count($row), "?"));
        $db->prepare("INSERT INTO modules (" . $columns . ") VALUES (" . $marks . ")")->execute(array_values($row));
    }
    $lookup = $db->prepare("SELECT * FROM recordings WHERE filename = ? ORDER BY id");
    $delete = $db->prepare("DELETE FROM recordings WHERE filename = ?");
    foreach ($data["recordings"] as $filename => $rows) {
        $lookup->execute([$filename]);
        $current = $lookup->fetchAll(\PDO::FETCH_ASSOC);
        if ($current === $rows) { continue; }
        // New-release registration only adds reserved SLS rows and removes
        // legacy rows proven owned by this module. Refuse unrelated changes.
        if ($current && $rows) { throw new \RuntimeException("System Recording changed independently during installation: " . $filename); }
        if (!$rows && $current) {
            if (strpos($filename, "custom/SLS_Mass_Notify_") !== 0) { throw new \RuntimeException("Unexpected new legacy System Recording"); }
            foreach ($current as $recording) {
                if (strpos((string)($recording["displayname"] ?? ""), "SLS Mass Notify - ") !== 0 || strpos((string)($recording["description"] ?? ""), "Default Southland Servers ") !== 0) { throw new \RuntimeException("New System Recording is not owned by this installation"); }
            }
            $delete->execute([$filename]);
        }
        foreach ($rows as $recording) {
            $columns = implode(",", array_map(static function ($value) { return "`" . $value . "`"; }, array_keys($recording)));
            $marks = implode(",", array_fill(0, count($recording), "?"));
            $db->prepare("INSERT INTO recordings (" . $columns . ") VALUES (" . $marks . ")")->execute(array_values($recording));
        }
    }
    $db->commit();
} catch (\Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
$backup = \FreePBX::Create()->Backup;
$jobs = (array)$backup->getAll("backupList");
foreach ($data["backup_enrollment"] as $entry) {
    $job = $entry["job"];
    if (!array_key_exists($job, $jobs)) { continue; } // Never recreate a removed job.
    $selected = (array)$backup->getAll("modules_" . $job);
    if (($selected["slsmassnotifyserver"] ?? null) !== true) { continue; } // Preserve independent administrator edits.
    if ($entry["present"]) { $backup->setConfig("slsmassnotifyserver", $entry["value"], "modules_" . $job); }
    else { $backup->delConfig("slsmassnotifyserver", "modules_" . $job); }
}
require_once "/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php";
$dashboard = \FreePBX::Dashboard();
$order = $dashboard->getConfig("visualorder");
$dashboard->setConfig("allhooks", \DashboardHooks::genHooks(is_array($order) ? $order : []));
' <"$RECOVERY_DIR/module-registration.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

restore_previous_module_tree() {
  /usr/bin/python3 -I - "$MODULE_BACKUP_DIR" "$HAD_EXISTING_MODULE" <<'PY'
import os, stat, sys
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC

def directory(path):
    fd = os.open('/', flags)
    try:
        for part in path.split('/'):
            if not part: continue
            if part in ('.', '..'): raise RuntimeError('invalid recovery directory')
            child = os.open(part, flags, dir_fd=fd)
            os.close(fd)
            fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise
backup = directory(sys.argv[1])
web = directory('/var/www/html/admin/modules')
try:
    info = os.fstat(backup)
    if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('unsafe module recovery directory')
    try:
        os.stat('failed-module', dir_fd=backup, follow_symlinks=False)
    except FileNotFoundError: pass
    else: raise RuntimeError('failed module recovery location already exists')
    try: os.rename('slsmassnotifyserver', 'failed-module', src_dir_fd=web, dst_dir_fd=backup)
    except FileNotFoundError: pass
    try: previous = os.stat('slsmassnotifyserver', dir_fd=backup, follow_symlinks=False)
    except FileNotFoundError:
        if sys.argv[2] == '1': raise RuntimeError('previous module backup is missing')
    else:
        if not stat.S_ISDIR(previous.st_mode): raise RuntimeError('previous module backup is unsafe')
        os.rename('slsmassnotifyserver', 'slsmassnotifyserver', src_dir_fd=backup, dst_dir_fd=web)
    os.fsync(web)
    os.fsync(backup)
finally:
    os.close(web)
    os.close(backup)
PY
}

rollback_module_install() {
  ROLLBACK_ATTEMPTED=1
  log "Restoring preserved static integration and module registration without running previous install/uninstall hooks."
  if [ -z "$RECOVERY_TOOL" ] || [ -z "$RECOVERY_DIR" ]; then
    rollback_failure "the authenticated recovery helper or snapshot is unavailable"
    return 1
  fi
  if [ "$MODULE_ACTIVATED" -eq 1 ] || { [ -n "$MODULE_BACKUP_DIR" ] && { [ -f "$MODULE_BACKUP_DIR/module-swap-complete" ] || [ -d "$MODULE_BACKUP_DIR/$MODULE" ] || { [ -n "$STAGING_DIR" ] && [ ! -d "$STAGING_DIR/$MODULE" ]; }; }; }; then
    restore_previous_module_tree || rollback_failure "the previous module directory could not be restored"
  fi
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" restore >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
    || rollback_failure "the static integration snapshot could not be fully restored"
  sls_as_asterisk /usr/sbin/asterisk -rx "manager reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || rollback_failure "restored Asterisk manager configuration did not reload"
  sls_as_asterisk /usr/sbin/asterisk -rx "dialplan reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || rollback_failure "restored Asterisk dialplan did not reload"
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" restore-services >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
    || rollback_failure "the prior collector/Apache process state could not be restored safely"
  restore_module_registration || rollback_failure "the original module registration and Dashboard hook index could not be restored"
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" verify >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
    || rollback_failure "restored static integration differs from its preserved snapshot"
  if [ "$HAD_EXISTING_MODULE" -eq 1 ] && [ "$ROLLBACK_FAILED" -eq 0 ]; then
    verify_local_api_route /api/sipnotify/desktop '^(401|429)$' '401/429' 'Recovered Desktop API' \
      || rollback_failure "the recovered Desktop API did not pass its read-only route check"
    verify_local_api_route /api/sls-mass-notify/ '^(401|403|405|429)$' '401/403/405/429' 'Recovered Control API' \
      || rollback_failure "the recovered Control API did not pass its read-only route check"
  fi
  verify_install_idle_history || rollback_failure "recovered notification idleness or retained phone history could not be verified"
  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" --workspace "$UPGRADE_TRUST_DIR" --verify-previous >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
      || rollback_failure "the recovered SLS, Dashboard or Framework files differ from their pre-install authenticated approvals"
  fi
  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" restore-maintenance >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
      || rollback_failure "previous root maintenance could not be restored against its pre-install authenticated approval; root maintenance remains disabled"
  fi
  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    ROLLBACK_VERIFIED=1
    log "Rollback completed. The authenticated previous runtime and its maintenance schedule were restored; active/pending settings and delivery history were preserved."
    return 0
  fi
  return 1
}


guard_config_on_exit() {
  local status=$? current_hash
  INSTALL_EXIT_CODE="$status"
  trap - EXIT
  # A failed recovery command must not interrupt later config restoration or
  # delete the only recovery copy. Preserve the original install exit status.
  set +e
  # EXIT status alone is not a commit marker. A premature successful exit or
  # a signal observed after a successful child must still restore changed state.
  if { [ "$STATIC_MUTATION_STARTED" -eq 1 ] || [ -n "$INSTALL_TRANSACTION_DIR" ]; } \
    && [ "$INSTALL_COMMITTED" -eq 0 ] && [ "$status" -eq 0 ]; then status=1; fi

  if [ "$status" -ne 0 ] && [ "$SYSTEM_TIMEZONE_CHANGED" -eq 1 ] && [ -n "$ORIGINAL_SYSTEM_TIMEZONE" ]; then
    if set_system_timezone "$ORIGINAL_SYSTEM_TIMEZONE" \
      && timezone_names_equivalent "$(detect_system_timezone 2>/dev/null || true)" "$ORIGINAL_SYSTEM_TIMEZONE"; then
      log "The installer restored the original system timezone after the failed installation."
      SYSTEM_TIMEZONE_CHANGED=0
    else
      log "CRITICAL: the installer could not restore the original system timezone '$ORIGINAL_SYSTEM_TIMEZONE'."
      log "Run: timedatectl set-timezone $ORIGINAL_SYSTEM_TIMEZONE"
      rollback_failure "the original system timezone could not be restored"
    fi
  fi

  if [ -n "$CONFIG_SNAPSHOT" ] && [ -f "$CONFIG_SNAPSHOT" ]; then
    current_hash="$(safe_config_hash "$CONFIG_FILE" 2>/dev/null || true)"
    if [ "$current_hash" != "$CONFIG_HASH_BEFORE" ]; then
      if ensure_data_directory 2>/dev/null && safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE" 2>/dev/null; then
        log "The installer restored the original central config after an interrupted or failed install."
        [ "$status" -ne 0 ] || status=1
      else
        log "CRITICAL: the installer could not safely restore the protected central config. The root-owned snapshot remains at $CONFIG_SNAPSHOT."
        rollback_failure "the protected central configuration could not be restored"
        [ "$status" -ne 0 ] || status=1
      fi
    fi
  fi

  if [ "$PENDING_CONFIG_STATE" = present ] && ! verify_pending_config_unchanged; then
    if safe_config_restore "$PENDING_CONFIG_SNAPSHOT" "$PENDING_CONFIG_PATH"; then
      log 'Restored the original pending settings without applying them.'
      [ "$status" -ne 0 ] || status=1
    else
      rollback_failure "the original pending configuration could not be restored"
      [ "$status" -ne 0 ] || status=1
    fi
  elif [ "$PENDING_CONFIG_STATE" = absent ] && ! verify_pending_config_unchanged; then
    rollback_failure "unexpected pending settings were preserved for administrator review"
    [ "$status" -ne 0 ] || status=1
  fi

  if [ "$status" -ne 0 ] && [ "$STATIC_MUTATION_STARTED" -eq 1 ] && [ "$INSTALL_COMMITTED" -eq 0 ]; then
    persist_install_transaction recovering || rollback_failure 'the persistent recovery journal could not be updated'
    rollback_module_install
  fi

  if [ "$ROLLBACK_ATTEMPTED" -eq 1 ] && [ -n "$CONFIG_HASH_BEFORE" ] && [ "$(safe_config_hash "$CONFIG_FILE" 2>/dev/null)" != "$CONFIG_HASH_BEFORE" ]; then
    rollback_failure "restoration changed the protected configuration"
    safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE" || log "CRITICAL: the original configuration requires manual restoration."
  fi

  if [ "$INSTALL_COMMITTED" -eq 0 ] && ! restore_freepbx_chown_permissions; then
    rollback_failure 'the original FreePBX chown configuration could not be restored; preserve the permission snapshot and review administrator changes'
    [ "$status" -ne 0 ] || status=1
  fi
  if [ "$RECOVERY_REQUEST_MODE" -eq 1 ] && [ "$STATIC_MUTATION_STARTED" -eq 0 ] && [ "$ROLLBACK_FAILED" -eq 0 ]; then
    # A crash during approved metadata preparation has no module/runtime
    # snapshot to restore. The bounded native configuration restoration above
    # completes this transaction without inventing absent central settings.
    ROLLBACK_VERIFIED=1
  fi

  # Snapshots are disposable only after an explicit commit, verified rollback,
  # or a transaction which never began changing protected state.
  if [ "$ROLLBACK_FAILED" -eq 0 ] && { [ "$INSTALL_COMMITTED" -eq 1 ] || [ "$ROLLBACK_VERIFIED" -eq 1 ] || [ "$STATIC_MUTATION_STARTED" -eq 0 ]; }; then
    local finish_phase=abandoned
    if [ "$INSTALL_COMMITTED" -eq 1 ]; then finish_phase=committed; elif [ "$ROLLBACK_VERIFIED" -eq 1 ]; then finish_phase=rolled_back; fi
    persist_install_transaction "$finish_phase" || rollback_failure 'verified completion could not be recorded durably; evidence remains protected'
  fi
  if [ "$ROLLBACK_FAILED" -eq 0 ] && { [ "$INSTALL_COMMITTED" -eq 1 ] || [ "$ROLLBACK_VERIFIED" -eq 1 ] || [ "$STATIC_MUTATION_STARTED" -eq 0 ]; }; then
    if [ -n "$CONFIG_SNAPSHOT" ] && [ -f "$CONFIG_SNAPSHOT" ]; then
      rm -f "$CONFIG_SNAPSHOT"
      CONFIG_SNAPSHOT=""
    fi
    [ -z "$PENDING_CONFIG_SNAPSHOT" ] || rm -f -- "$PENDING_CONFIG_SNAPSHOT"
    [ -z "$STAGING_DIR" ] || rm -rf -- "$STAGING_DIR"
    [ -z "$MODULE_BACKUP_DIR" ] || rm -rf -- "$MODULE_BACKUP_DIR"
    [ -z "$RECOVERY_DIR" ] || rm -rf -- "$RECOVERY_DIR"
    [ -z "$INSTALL_BOOTSTRAP_DIR" ] || rm -rf -- "$INSTALL_BOOTSTRAP_DIR"
    if [ -n "$INSTALL_TRANSACTION_DIR" ]; then
      rm -f -- "$INSTALL_TRANSACTION_DIR/package.tgz" "$INSTALL_TRANSACTION_DIR/installer.sh" "$INSTALL_TRANSACTION_DIR/release-manifest.json" "$INSTALL_TRANSACTION_DIR/release-manifest.sig"
      [ -z "$UPGRADE_TRUST_DIR" ] || rm -rf -- "$UPGRADE_TRUST_DIR"
      # Only a small durable completion marker survives successful finalization.
      # Unfinished transactions retain all authentication and approval evidence.
    fi
  else
    persist_install_transaction recovery_failed || true
    [ "$status" -ne 0 ] || status=1
    log "Recovery locations: module=${MODULE_BACKUP_DIR:-none}; config=${CONFIG_SNAPSHOT:-none}; pending=${PENDING_CONFIG_SNAPSHOT:-none}; stage=${STAGING_DIR:-none}; static=${RECOVERY_DIR:-none}; helper=${INSTALL_BOOTSTRAP_DIR:-none}; approvals=${UPGRADE_TRUST_DIR:-none}."
    INSTALL_ERROR_CATEGORY="install_rollback_failed"
    if [ "$ROLLBACK_ATTEMPTED" -eq 1 ]; then
      INSTALL_SOLUTION="Recovery requires review: SLS root update/maintenance jobs remain disabled if prior authenticated execution approval or restoration could not be verified. Preserve the module, config, static-state and helper locations in the log; resolve any additional restoration failures before retrying."
    else
      INSTALL_SOLUTION="Protected preflight recovery was incomplete. Preserve the recovery locations printed in the installer log and correct the specified configuration or timezone restoration failure before retrying."
    fi
  fi
  [ -z "$DOWNLOAD_DIR" ] || rm -rf -- "$DOWNLOAD_DIR"

  if [ "$status" -ne 0 ]; then
    if [ "$RECOVERY_REQUEST_MODE" -eq 1 ] && [ "$ROLLBACK_VERIFIED" -eq 1 ] && [ "$ROLLBACK_FAILED" -eq 0 ]; then
      clear_install_failure
      log 'Interrupted installation recovered. Rerun the normal installer to install the candidate.'
      status=0
    fi
  fi
  if [ "$status" -ne 0 ]; then
    INSTALL_EXIT_CODE="$status"
    record_install_failure
  fi

  release_worker_coordination
  exit "$status"
}

timezone_zoneinfo_path() {
  local timezone_name="$1"

  TIMEZONE_NAME="$timezone_name" /usr/bin/python3 -I - <<'PY'
import os
import pathlib
import re

name = os.environ.get("TIMEZONE_NAME", "")
if not name or len(name) > 128 or "\x00" in name:
    raise SystemExit(1)
if name.startswith("/") or "//" in name or any(part in {"", ".", ".."} for part in name.split("/")):
    raise SystemExit(1)
if not re.fullmatch(r"[A-Za-z0-9._+-]+(?:/[A-Za-z0-9._+-]+)*", name):
    raise SystemExit(1)

base = pathlib.Path("/usr/share/zoneinfo").resolve(strict=True)
candidate = base.joinpath(*name.split("/"))
resolved = candidate.resolve(strict=True)
try:
    resolved.relative_to(base)
except ValueError:
    raise SystemExit(1)
if not resolved.is_file():
    raise SystemExit(1)
print(resolved)
PY
}

validate_system_timezone() {
  local timezone_name="$1"
  local listed_timezones

  timezone_zoneinfo_path "$timezone_name" >/dev/null 2>&1 || return 1
  [ -x /usr/bin/timedatectl ] || return 1
  listed_timezones="$(/usr/bin/timedatectl list-timezones --no-pager 2>/dev/null || true)"
  [ -n "$listed_timezones" ] || return 1
  LC_ALL=C grep -Fxq -- "$timezone_name" <<<"$listed_timezones"
}

detect_system_timezone() {
  local timezone_name=""

  if [ -x /usr/bin/timedatectl ]; then
    timezone_name="$(/usr/bin/timedatectl show --property=Timezone --value 2>/dev/null || true)"
  fi
  if ! timezone_zoneinfo_path "$timezone_name" >/dev/null 2>&1; then
    if [ -r /etc/timezone ] && [ ! -L /etc/timezone ]; then
      timezone_name="$(sed -n '1{s/[[:space:]]*$//;p;q;}' /etc/timezone)"
    fi
  fi
  timezone_zoneinfo_path "$timezone_name" >/dev/null 2>&1 || return 1
  printf '%s\n' "$timezone_name"
}

timezone_names_equivalent() {
  local left="$1"
  local right="$2"
  local left_path right_path

  [ -n "$left" ] && [ -n "$right" ] || return 1
  left_path="$(timezone_zoneinfo_path "$left" 2>/dev/null || true)"
  right_path="$(timezone_zoneinfo_path "$right" 2>/dev/null || true)"
  [ -n "$left_path" ] && [ "$left_path" = "$right_path" ]
}

set_system_timezone() {
  local timezone_name="$1"

  /usr/bin/timedatectl set-timezone "$timezone_name"
}

configure_system_timezone() {
  local detected_timezone requested_timezone response confirmed_timezone

  detected_timezone="$(detect_system_timezone 2>/dev/null || true)"
  [ -n "$detected_timezone" ] || {
    log "The installer could not detect a valid IANA system timezone through timedatectl and /usr/share/zoneinfo."
    return 1
  }
  ORIGINAL_SYSTEM_TIMEZONE="$detected_timezone"
  log "Detected system timezone: $detected_timezone"

  requested_timezone="$REQUESTED_SYSTEM_TIMEZONE"

  if [ -z "$requested_timezone" ]; then
    if [ ! -t 0 ] || [ ! -t 1 ]; then
      log "Noninteractive install: keeping $detected_timezone. Set SLS_MASS_NOTIFY_TIMEZONE to a listed IANA timezone to change it explicitly."
    else
      log "System timezone confirmed: $detected_timezone"
    fi
    CONFIRMED_SYSTEM_TIMEZONE="$detected_timezone"
    return 0
  fi

  if ! validate_system_timezone "$requested_timezone"; then
    log "The requested system timezone is not a valid listed IANA timezone."
    log "Choose an exact value from: timedatectl list-timezones"
    return 1
  fi
  if timezone_names_equivalent "$detected_timezone" "$requested_timezone"; then
    log "System timezone confirmed: $detected_timezone"
    CONFIRMED_SYSTEM_TIMEZONE="$detected_timezone"
    return 0
  fi

  log "Changing system timezone from $detected_timezone to $requested_timezone."
  SYSTEM_TIMEZONE_CHANGED=1
  persist_install_transaction mutating || return 1
  set_system_timezone "$requested_timezone" || {
    log "timedatectl could not set the requested system timezone."
    return 1
  }
  confirmed_timezone="$(detect_system_timezone 2>/dev/null || true)"
  if ! timezone_names_equivalent "$confirmed_timezone" "$requested_timezone"; then
    log "System timezone verification failed after timedatectl returned success."
    if set_system_timezone "$detected_timezone" >/dev/null 2>&1 \
      && timezone_names_equivalent "$(detect_system_timezone 2>/dev/null || true)" "$detected_timezone"; then
      SYSTEM_TIMEZONE_CHANGED=0
    fi
    return 1
  fi
  CONFIRMED_SYSTEM_TIMEZONE="$confirmed_timezone"
  log "System timezone is now: $confirmed_timezone"
}

verify_confirmed_system_timezone() {
  local current_timezone

  [ -n "$CONFIRMED_SYSTEM_TIMEZONE" ] || return 1
  current_timezone="$(detect_system_timezone 2>/dev/null || true)"
  if ! timezone_names_equivalent "$current_timezone" "$CONFIRMED_SYSTEM_TIMEZONE"; then
    log "The system timezone changed unexpectedly during installation."
    return 1
  fi
  log "System timezone verified: $current_timezone"
}

require_freepbx() {
  local bootstrap_utility
  [ "${EUID:-$(id -u)}" -eq 0 ] || {
    log "Run the installer as root."
    exit 1
  }
  case "$REPAIR_ASTERISK_PACKAGES" in
    0|1) ;;
    *)
      log "SLS_MASS_NOTIFY_REPAIR_ASTERISK_PACKAGES must be 0 or 1."
      exit 1
      ;;
  esac
  [ -x /usr/sbin/fwconsole ] || {
    log "The required FreePBX CLI is unavailable at /usr/sbin/fwconsole. This runtime cannot activate safely on a nonstandard FreePBX path."
    exit 1
  }
  [ -d /var/www/html/admin/modules ] || {
    log "/var/www/html/admin/modules not found. This does not look like a FreePBX server."
    exit 1
  }
  [ -x /usr/sbin/asterisk ] || {
    log "The required Asterisk CLI is unavailable at /usr/sbin/asterisk. This runtime cannot activate safely on a nonstandard Asterisk path."
    exit 1
  }
  [ -r /etc/freepbx.conf ] || {
    log "/etc/freepbx.conf is missing or unreadable."
    exit 1
  }
  FREEPBX_CONFIRMED=1
  # A minimal FreePBX image may not yet have the locking/process utilities or
  # PHP features needed by the coordination and bootstrap checks below. Repair
  # those only after the target has been identified as a FreePBX host, but
  # before trying to use them.
  install_bootstrap_dependencies
  for bootstrap_utility in /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/sbin/runuser; do
    [ -x "$bootstrap_utility" ] || {
      log "Required installation coordination utility is unavailable: $bootstrap_utility"
      exit 1
    }
  done
  php -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
\FreePBX::Database()->query("SELECT 1");
$managerHost = strtolower(trim((string)\FreePBX::Config()->get("ASTMANAGERHOST")));
$managerPort = (int)\FreePBX::Config()->get("ASTMANAGERPORT");
if ($managerHost === "") {
    $managerHost = "localhost";
}
if (!in_array($managerHost, ["localhost", "127.0.0.1", "::1"], true)) {
    fwrite(STDERR, "SLS Mass Notify requires FreePBX AMI to use a loopback host; configured ASTMANAGERHOST is unsupported.\n");
    exit(1);
}
if ($managerPort < 1 || $managerPort > 65535) {
    fwrite(STDERR, "FreePBX ASTMANAGERPORT is invalid.\n");
    exit(1);
}
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log "FreePBX bootstrap or database access failed. See $LOG_FILE."
    exit 1
  }
  fwconsole --version 2>/dev/null | grep -Eq '(^|[[:space:]])17\.' || {
    log "This release requires FreePBX 17."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials."
    exit 1
  }
}

acquire_maintenance_coordination() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
  local descriptor descriptor_path descriptor_target

  [[ "$lock_file" = /* ]] && [ "$lock_file" != "/" ] || {
    log "Unsafe maintenance lock path."
    exit 1
  }
  mkdir -p "$(dirname "$lock_file")"
  [ ! -L "$lock_file" ] || {
    log "Refusing a symbolic-link maintenance lock: $lock_file"
    exit 1
  }

  # A UI-triggered update is launched by the maintenance worker while it holds
  # this lock. Reuse that inherited lock instead of deadlocking the child
  # installer; direct CLI installs acquire their own lock and keep the minute
  # maintenance job out of the module/signing transaction.
  for descriptor_path in "/proc/${BASHPID}/fd/"*; do
    [ -e "$descriptor_path" ] || continue
    descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
    [ "$descriptor_target" = "$lock_file" ] || continue
    descriptor="${descriptor_path##*/}"
    if [[ "$descriptor" =~ ^[0-9]+$ ]] && flock -n "$descriptor"; then
      log "Using the maintenance worker's inherited installation lock."
      return 0
    fi
  done

  open_root_owned_file INSTALL_MAINTENANCE_LOCK_FD "$lock_file" || {
    log "The protected maintenance lock could not be opened safely."
    exit 1
  }
  flock -w 120 "$INSTALL_MAINTENANCE_LOCK_FD" || {
    log "Another Mass Notify maintenance or update operation is still running."
    log "Wait for it to finish, then rerun the installer."
    exit 1
  }
}

acquire_update_coordination() {
  local lock_file=/run/lock/sls-mass-notify-update.lock descriptor target
  for target in "/proc/${BASHPID}/fd/"*; do
    [ "$(readlink -f -- "$target" 2>/dev/null || true)" = "$lock_file" ] || continue
    descriptor="${target##*/}"
    if [[ "$descriptor" =~ ^[0-9]+$ ]] && flock -n "$descriptor"; then return 0; fi
  done
  open_root_owned_file INSTALL_UPDATE_LOCK_FD "$lock_file" || return 1
  flock -w 120 "$INSTALL_UPDATE_LOCK_FD" || { log 'Another SLS update is still running; no release files were changed.'; return 1; }
}

acquire_worker_coordination() {
  ensure_data_directory || return 1
  # A dedicated child owns all worker locks; no PHP/service process inherits
  # their descriptors. Root only opens validated service-account lock files.
  [ -n "$WORKER_GUARD_TOOL" ] && [ -f "$WORKER_GUARD_TOOL" ] && [ ! -L "$WORKER_GUARD_TOOL" ] || {
    log 'The authenticated worker guard helper has not been staged.'
    return 1
  }
  coproc SLS_INSTALL_WORKER_GUARD {
    /usr/bin/python3 -I "$WORKER_GUARD_TOOL" --data "$DATA_DIR" --timeout 120 hold
  }
  WORKER_LOCK_PID="${SLS_INSTALL_WORKER_GUARD_PID:-}"
  local guard_input="${SLS_INSTALL_WORKER_GUARD[1]:-}" guard_output="${SLS_INSTALL_WORKER_GUARD[0]:-}" status
  # Bash removes a coprocess's array/PID when an immediately rejected child
  # exits. Treat that race as the same controlled readiness failure.
  if [ -z "$WORKER_LOCK_PID" ] || [ -z "$guard_input" ] || [ -z "$guard_output" ] \
    || ! exec {WORKER_LOCK_KEEPALIVE_FD}>&"$guard_input" \
    || ! exec {WORKER_LOCK_RESPONSE_FD}<&"$guard_output"; then
    log 'Unable to pause active notification workers safely. No release files were activated.'
    release_worker_coordination
    return 1
  fi
  if ! IFS= read -r status <&"$WORKER_LOCK_RESPONSE_FD" || [ "$status" != ready ]; then
    log 'Unable to pause active notification workers safely. No release files were activated.'
    release_worker_coordination
    return 1
  fi
}

release_worker_coordination() {
  if [ -n "${WORKER_LOCK_KEEPALIVE_FD:-}" ]; then
    # A guard rejected before readiness may have already closed its pipe.
    # Isolate SIGPIPE so cleanup cannot kill the installer with exit 141.
    (printf 'release\n' >&"$WORKER_LOCK_KEEPALIVE_FD") 2>/dev/null || true
    exec {WORKER_LOCK_KEEPALIVE_FD}>&-
    WORKER_LOCK_KEEPALIVE_FD=""
  fi
  [ -z "${WORKER_LOCK_PID:-}" ] || wait "$WORKER_LOCK_PID" 2>/dev/null || true
  WORKER_LOCK_PID=""
  if [ -n "${WORKER_LOCK_RESPONSE_FD:-}" ]; then
    exec {WORKER_LOCK_RESPONSE_FD}<&-
    WORKER_LOCK_RESPONSE_FD=""
  fi
}

close_inherited_maintenance_lock_fds() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
  local descriptor descriptor_path descriptor_target close_fd

  for descriptor_path in "/proc/${BASHPID}/fd/"*; do
    [ -e "$descriptor_path" ] || continue
    descriptor="${descriptor_path##*/}"
    [[ "$descriptor" =~ ^[0-9]+$ ]] && [ "$descriptor" -gt 2 ] || continue
    descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
    [ "$descriptor_target" = "$lock_file" ] || [ "$descriptor_target" = /run/lock/sls-mass-notify-update.lock ] || [ "$descriptor_target" = "$SETTINGS_LOCK" ] || continue
    close_fd="$descriptor"
    exec {close_fd}>&-
  done
}

run_without_install_maintenance_lock() (
  close_inherited_maintenance_lock_fds
  # A detached descendant must not keep the worker guard alive after our exit.
  if [ -n "${WORKER_LOCK_KEEPALIVE_FD:-}" ]; then
    exec {WORKER_LOCK_KEEPALIVE_FD}>&-
  fi
  if [ -n "${WORKER_LOCK_RESPONSE_FD:-}" ]; then
    exec {WORKER_LOCK_RESPONSE_FD}<&-
  fi
  "$@"
)

ensure_freepbx_prerequisites() {
  local prerequisite module_list
  module_list="$(fwconsole ma list 2>/dev/null)"
  for prerequisite in framework dashboard backup recordings; do
    if ! printf '%s\n' "$module_list" | grep -Eq "\\|[[:space:]]*${prerequisite}[[:space:]]*\\|"; then
      log "Installing required FreePBX module: $prerequisite"
      fwconsole ma --no-interaction --ignorecache downloadinstall "$prerequisite" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
        log "Required FreePBX module could not be installed: $prerequisite. See $LOG_FILE."
        exit 1
      }
    fi
    module_list="$(fwconsole ma list 2>/dev/null)"
    if ! printf '%s\n' "$module_list" | grep -Eq "\\|[[:space:]]*${prerequisite}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|"; then
      log "Enabling required FreePBX module: $prerequisite"
      fwconsole ma --no-interaction enable "$prerequisite" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
        log "Required FreePBX module could not be enabled without an administrator-managed upgrade: $prerequisite. See $LOG_FILE."
        exit 1
      }
    fi
  done
}

strip_asterisk_ansi() {
  # Asterisk can emit terminal color/control sequences even when invoked from
  # a noninteractive installer. Remove CSI sequences before parsing headings.
  LC_ALL=C sed -E $'s/\033\\[[0-?]*[ -\\/]*[@-~]//g'
}

asterisk_cli_output() {
  local command="$1"
  local output

  output="$(asterisk -rx "$command" 2>&1 || true)"
  strip_asterisk_ansi <<<"$output"
}

asterisk_setting_value() {
  local setting_name="$1"
  local settings

  settings="$(asterisk_cli_output "core show settings")"
  printf '%s\n' "$settings" | awk -v wanted="$setting_name" '
function trim(value) {
  sub(/^[[:space:]]+/, "", value)
  sub(/[[:space:]]+$/, "", value)
  return value
}
{
  separator = index($0, ":")
  if (separator == 0) {
    next
  }
  key = trim(substr($0, 1, separator - 1))
  if (tolower(key) == tolower(wanted)) {
    print trim(substr($0, separator + 1))
    exit
  }
}'
}

asterisk_capability_available() {
  local capability_type="$1"
  local capability_name="$2"
  local capability_info marker

  case "$capability_type" in
    function)
      capability_info="$(asterisk_cli_output "core show function $capability_name")"
      marker="Info about Function '$capability_name'"
      ;;
    application)
      capability_info="$(asterisk_cli_output "core show application $capability_name")"
      marker="Info about Application '$capability_name'"
      ;;
    *)
      log "Unknown Asterisk capability type: $capability_type"
      return 1
      ;;
  esac

  # Asterisk releases and downstream builds vary the heading capitalization
  # ("Function" versus "function"). Match the complete quoted capability
  # heading case-insensitively so a registered provider never falls through to
  # package repair because of presentation-only CLI differences.
  LC_ALL=C grep -Fqi -- "$marker" <<<"$capability_info"
}

asterisk_module_file() {
  local module_name="$1"
  local module_directory

  module_directory="$(asterisk_setting_value "Module directory")"
  if [ -n "$module_directory" ] && [[ "$module_directory" = /* ]]; then
    printf '%s/%s\n' "${module_directory%/}" "$module_name"
  fi
}

refresh_apt_metadata() {
  if [ "$APT_METADATA_REFRESHED" -eq 1 ]; then
    return 0
  fi
  command -v apt-get >/dev/null 2>&1 || return 1
  log "Refreshing Debian package metadata for dependency verification."
  apt-get update >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || return 1
  APT_METADATA_REFRESHED=1
}

asterisk_provider_package() {
  local provider_file="$1"
  local canonical_provider query record package record_path canonical_record status
  local -a queries

  command -v dpkg-query >/dev/null 2>&1 || return 1
  canonical_provider="$(readlink -m -- "$provider_file" 2>/dev/null || true)"
  [ -n "$canonical_provider" ] || return 1
  queries=("$provider_file")
  if [ "$canonical_provider" != "$provider_file" ]; then
    queries+=("$canonical_provider")
  fi
  queries+=("*/asterisk/modules/$(basename "$provider_file")")

  for query in "${queries[@]}"; do
    while IFS= read -r record; do
      [[ "$record" == *": "* ]] || continue
      package="${record%%: *}"
      record_path="${record#*: }"
      [[ "$package" =~ ^[a-z0-9][a-z0-9+.-]*(:[a-z0-9][a-z0-9-]*)?$ ]] || continue
      canonical_record="$(readlink -m -- "$record_path" 2>/dev/null || true)"
      [ "$canonical_record" = "$canonical_provider" ] || continue
      status="$(dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null || true)"
      [[ "$status" == ii* ]] || continue
      printf '%s\n' "$package"
      return 0
    done < <(dpkg-query -S "$query" 2>/dev/null || true)
  done
  return 1
}

wait_for_asterisk_cli() {
  local attempt=1
  while [ "$attempt" -le 30 ]; do
    if asterisk -rx "core show version" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
      return 0
    fi
    sleep 1
    attempt=$((attempt + 1))
  done
  return 1
}

repair_asterisk_provider_package() {
  local provider_module="$1"
  local provider_file package package_version package_spec utility
  local active_channels

  [ "$REPAIR_ASTERISK_PACKAGES" -eq 1 ] || {
    log "Automatic Asterisk provider-package repair is disabled."
    return 1
  }
  for utility in apt-get dpkg-query readlink; do
    command -v "$utility" >/dev/null 2>&1 || {
      log "Automatic Asterisk provider-package repair requires $utility, but it is unavailable."
      return 1
    }
  done
  if ! asterisk -rx "core show version" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "Asterisk is not reachable; refusing to start or alter its packages automatically."
    return 1
  fi
  active_channels="$(asterisk -rx "core show channels count" 2>/dev/null \
    | awk '$2 == "active" && $3 == "channels" {print $1; exit}')"
  if ! [[ "$active_channels" =~ ^[0-9]+$ ]]; then
    log "Unable to verify that Asterisk has no active channels; automatic package repair was deferred."
    return 1
  fi
  if [ "$active_channels" -gt 0 ]; then
    log "Automatic Asterisk package repair was deferred because $active_channels channel(s) are active."
    log "Rerun the installer after active calls have completed."
    return 1
  fi
  provider_file="$(asterisk_module_file "$provider_module")"
  [ -n "$provider_file" ] || {
    log "Unable to determine the active Asterisk module directory for $provider_module."
    return 1
  }
  package="$(asterisk_provider_package "$provider_file" || true)"
  [ -n "$package" ] || {
    log "No installed Debian package owns $provider_file; this appears to be a custom or incomplete Asterisk build."
    log "The installer will not mix a packaged module into an unowned Asterisk build because that can corrupt the PBX ABI."
    return 1
  }
  package_version="$(dpkg-query -W -f='${Version}' "$package" 2>/dev/null || true)"
  [ -n "$package_version" ] || {
    log "Unable to determine the installed version of the Asterisk provider package $package."
    return 1
  }
  package_spec="${package}=${package_version}"
  if [[ "$ASTERISK_PACKAGE_REPAIR_ATTEMPTS" == *"|${package_spec}|"* ]]; then
    log "The exact Asterisk package $package_spec was already repaired during this installer run."
    return 1
  fi
  ASTERISK_PACKAGE_REPAIR_ATTEMPTS="${ASTERISK_PACKAGE_REPAIR_ATTEMPTS}|${package_spec}|"

  refresh_apt_metadata || {
    log "Unable to refresh Debian package metadata needed to repair $provider_module. See $LOG_FILE."
    return 1
  }

  log "Repairing $provider_module from the exact installed Asterisk package $package_spec."
  DEBIAN_FRONTEND=noninteractive apt-get install -y --reinstall --no-install-recommends --no-remove \
    "$package_spec" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
      log "Exact-version Asterisk package repair failed for $package_spec. See $LOG_FILE."
      log "Restore that exact package version or its matching repository before rerunning the installer."
      return 1
    }
  [ -f "$provider_file" ] || {
    log "Package repair completed, but $provider_file is still missing."
    return 1
  }
  if ! wait_for_asterisk_cli; then
    log "Asterisk stopped during package repair; attempting its system service start without bootstrapping FreePBX as root."
    /usr/bin/systemctl start asterisk.service >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
      log "FreePBX could not start Asterisk after package repair. See $LOG_FILE."
      return 1
    }
    wait_for_asterisk_cli || {
      log "Asterisk did not return after the exact-version package repair. See $LOG_FILE."
      return 1
    }
  fi
  return 0
}

asterisk_module_loaded() {
  local module_name="$1"
  local module_info line normalized_module_name field_count
  local -a fields
  module_info="$(asterisk_cli_output "module show like $module_name")"
  normalized_module_name="${module_name,,}"
  while IFS= read -r line; do
    read -r -a fields <<<"$line"
    field_count="${#fields[@]}"
    if [ "$field_count" -ge 3 ] \
      && [ "${fields[0],,}" = "$normalized_module_name" ] \
      && [ "${fields[field_count - 2],,}" = "running" ] \
      && { [ "$field_count" -lt 4 ] || [ "${fields[field_count - 3],,}" != "not" ]; }; then
      return 0
    fi
  done <<<"$module_info"
  return 1
}

require_asterisk_capability() {
  local capability_type="$1"
  local capability_name="$2"

  if asterisk_capability_available "$capability_type" "$capability_name"; then
    return 0
  fi
  log "Required built-in Asterisk $capability_type is unavailable: $capability_name. The installer cannot safely repair a built-in capability."
  return 1
}

ensure_asterisk_module_loaded() {
  local module_name="$1"

  if asterisk_module_loaded "$module_name"; then
    return 0
  fi
  log "Loading required Asterisk module $module_name."
  asterisk -rx "module load $module_name" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  if ! asterisk_module_loaded "$module_name" \
    && repair_asterisk_provider_package "$module_name"; then
    asterisk -rx "module load $module_name" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  fi
  asterisk_module_loaded "$module_name" || {
    log "Required Asterisk module is unavailable after adaptive repair: $module_name. See $LOG_FILE."
    return 1
  }
}

ensure_asterisk_capability() {
  local capability_type="$1"
  local capability_name="$2"
  local provider_module="$3"
  local provider_file

  if asterisk_capability_available "$capability_type" "$capability_name"; then
    return 0
  fi

  log "Asterisk $capability_type $capability_name is not registered; loading $provider_module."
  asterisk -rx "module load $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  if ! asterisk_capability_available "$capability_type" "$capability_name"; then
    asterisk -rx "module reload $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  fi
  if ! asterisk_capability_available "$capability_type" "$capability_name" \
    && repair_asterisk_provider_package "$provider_module"; then
    asterisk -rx "module load $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    if ! asterisk_capability_available "$capability_type" "$capability_name"; then
      asterisk -rx "module reload $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    fi
  fi
  if ! asterisk_capability_available "$capability_type" "$capability_name"; then
    provider_file="$(asterisk_module_file "$provider_module")"
    if [ -n "$provider_file" ] && [ ! -f "$provider_file" ]; then
      log "Asterisk $capability_type $capability_name requires $provider_module, but the active Asterisk build does not include $provider_file."
      log "Install the matching Asterisk core/module package for this PBX, then rerun the installer."
    else
      log "Asterisk could not register $capability_type $capability_name from $provider_module. See $LOG_FILE."
    fi
    return 1
  fi

}

ensure_required_asterisk_capabilities() {
  ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so || return 1
  ensure_asterisk_capability function PJSIP_CONTACT func_pjsip_contact.so || return 1
  ensure_asterisk_capability function PJSIP_AOR func_pjsip_aor.so || return 1
  ensure_asterisk_capability function PJSIP_DIAL_CONTACTS chan_pjsip.so || return 1
  ensure_asterisk_capability function TOLOWER func_strings.so || return 1
  ensure_asterisk_capability function CUT func_strings.so || return 1
  ensure_asterisk_capability function FILTER func_strings.so || return 1
  ensure_asterisk_capability function CHANNEL func_channel.so || return 1
  ensure_asterisk_capability function IF func_logic.so || return 1
  ensure_asterisk_capability function DB func_db.so || return 1
  ensure_asterisk_capability function CALLERID func_callerid.so || return 1
  ensure_asterisk_capability application ConfBridge app_confbridge.so || return 1
  ensure_asterisk_capability application Page app_page.so || return 1
  ensure_asterisk_capability application AGI res_agi.so || return 1
  ensure_asterisk_capability application Playback app_playback.so || return 1
  ensure_asterisk_capability application Read app_read.so || return 1
  ensure_asterisk_capability application ExecIf app_exec.so || return 1
  ensure_asterisk_capability application Gosub app_stack.so || return 1
  ensure_asterisk_capability application Return app_stack.so || return 1
  ensure_asterisk_capability application Log app_verbose.so || return 1
  ensure_asterisk_capability application Verbose app_verbose.so || return 1
  require_asterisk_capability application Wait || return 1
}

asterisk_module_starts_persistently() {
  local module_name="$1"
  local modules_config="${2:-/etc/asterisk/modules.conf}"
  [ -r "$modules_config" ] || return 1
  /usr/bin/awk -v wanted="${module_name,,}" '
    BEGIN { autoload = 0; explicit = 0; blocked = 0; modules = 0 }
    {
      line = $0
      sub(/[;#].*$/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      if (line ~ /^\[/) { modules = (tolower(line) == "[modules]"); next }
      if (!modules) next
      split(line, fields, /[[:space:]]*=>?[[:space:]]*/)
      key = tolower(fields[1])
      value = tolower(fields[2])
      if (key == "autoload" && value == "yes") autoload = 1
      if ((key == "load" || key == "preload" || key == "require" || key == "preload-require") && value == wanted) explicit = 1
      if (key == "noload" && value == wanted) blocked = 1
    }
    END { exit(blocked || (!autoload && !explicit) ? 1 : 0) }
  ' "$modules_config"
}

verify_asterisk_module_startup_persistence() {
  local module_name
  for module_name in \
    res_pjsip.so res_pjsip_session.so chan_pjsip.so res_pjsip_notify.so \
    pbx_spool.so format_wav.so res_pjsip_header_funcs.so \
    func_pjsip_contact.so func_pjsip_aor.so func_strings.so func_channel.so \
    func_logic.so func_db.so func_callerid.so app_exec.so \
    app_page.so app_confbridge.so app_stack.so app_verbose.so; do
    asterisk_module_starts_persistently "$module_name" || {
      log "Required Asterisk module is available now but is blocked after restart by /etc/asterisk/modules.conf: $module_name"
      log "Enable Asterisk module autoloading or explicitly load that module through the PBX-managed module configuration, then rerun the installer."
      return 1
    }
  done
}

asterisk_channel_type_available() {
  local channel_type="$1"
  local channel_info

  channel_info="$(asterisk_cli_output "core show channeltype $channel_type")"
  LC_ALL=C grep -Fqi -- "Info about channel driver: $channel_type" <<<"$channel_info"
}

verify_supported_asterisk_directories() {
  local spool_directory varlib_directory data_directory
  local canonical_spool canonical_varlib canonical_data

  spool_directory="$(asterisk_setting_value "Spool directory")"
  varlib_directory="$(asterisk_setting_value "VarLib directory")"
  data_directory="$(asterisk_setting_value "Data directory")"

  if [[ "$spool_directory" != /* ]]; then
    log "Unable to determine Asterisk's active Spool directory from 'core show settings'."
    return 1
  fi
  if [[ "$varlib_directory" != /* ]]; then
    log "Unable to determine Asterisk's active VarLib directory from 'core show settings'."
    return 1
  fi

  canonical_spool="$(readlink -m -- "$spool_directory" 2>/dev/null || true)"
  canonical_varlib="$(readlink -m -- "$varlib_directory" 2>/dev/null || true)"
  if [ "$canonical_spool" != "/var/spool/asterisk" ]; then
    log "Unsupported Asterisk Spool directory: $spool_directory. This release writes call files only to /var/spool/asterisk and will not activate against a different live spool path."
    return 1
  fi
  if [ "$canonical_varlib" != "/var/lib/asterisk" ]; then
    log "Unsupported Asterisk VarLib directory: $varlib_directory. This release stores sounds and state only under /var/lib/asterisk and will not activate against a different live data path."
    return 1
  fi

  # Some builds omit Data directory from the CLI. When it is reported, ensure
  # that the module's fixed sound path points at the active Asterisk data root.
  if [ -n "$data_directory" ]; then
    if [[ "$data_directory" != /* ]]; then
      log "Asterisk reported a non-absolute Data directory: $data_directory."
      return 1
    fi
    canonical_data="$(readlink -m -- "$data_directory" 2>/dev/null || true)"
    if [ "$canonical_data" != "/var/lib/asterisk" ]; then
      log "Unsupported Asterisk Data directory: $data_directory. This release expects /var/lib/asterisk for sounds."
      return 1
    fi
  fi

  return 0
}

asterisk_labeled_value() {
  local command="$1"
  local label="$2"
  local output

  output="$(asterisk_cli_output "$command")"
  printf '%s\n' "$output" | awk -v wanted="$label" '
function trim(value) {
  sub(/^[[:space:]]+/, "", value)
  sub(/[[:space:]]+$/, "", value)
  return value
}
{
  separator = index($0, ":")
  if (separator == 0) {
    next
  }
  key = trim(substr($0, 1, separator - 1))
  if (tolower(key) == tolower(wanted)) {
    print trim(substr($0, separator + 1))
    exit
  }
}'
}

pjsip_dial_string_supported() {
  local dial_string="$1"
  local channel
  local -a channels

  [ -n "$dial_string" ] || return 1
  IFS='&' read -r -a channels <<<"$dial_string"
  [ "${#channels[@]}" -gt 0 ] || return 1
  for channel in "${channels[@]}"; do
    channel="$(printf '%s' "$channel" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    [ -n "$channel" ] || return 1
    [[ "${channel,,}" == pjsip/* ]] || return 1
  done
}

pjsip_endpoint_available() {
  local endpoint="$1"
  local endpoint_info

  [[ "$endpoint" =~ ^[A-Za-z0-9_.-]+$ ]] || return 1
  endpoint_info="$(asterisk_cli_output "pjsip show endpoint $endpoint")"
  printf '%s\n' "$endpoint_info" | awk -v wanted="$endpoint" '
function trim(value) {
  sub(/^[[:space:]]+/, "", value)
  sub(/[[:space:]]+$/, "", value)
  return value
}
{
  separator = index($0, ":")
  if (separator == 0 || tolower(trim(substr($0, 1, separator - 1))) != "endpoint") {
    next
  }
  value = trim(substr($0, separator + 1))
  split(value, columns, /[[:space:]]+/)
  split(columns[1], endpoint_parts, "/")
  if (endpoint_parts[1] == wanted) {
    found = 1
  }
}
END {
  exit(found ? 0 : 1)
}'
}

pjsip_dial_string_endpoints_available() {
  local dial_string="$1"
  local channel endpoint
  local -a channels

  pjsip_dial_string_supported "$dial_string" || return 1
  IFS='&' read -r -a channels <<<"$dial_string"
  for channel in "${channels[@]}"; do
    channel="$(printf '%s' "$channel" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    endpoint="${channel#*/}"
    endpoint="${endpoint%%/*}"
    if ! pjsip_endpoint_available "$endpoint"; then
      log "PJSIP paging route component does not resolve to an active endpoint object: $channel"
      return 1
    fi
  done
}

verify_registered_endpoint_route() {
  local extension="$1"
  local device_route device_aor dial_string route_source

  [[ "$extension" =~ ^[0-9]+$ ]] || {
    log "Refusing to validate a nonnumeric paging extension: $extension"
    return 1
  }

  # Match the managed dialplan: explicit registered contacts are preferred so
  # Page creates one conference participant per contact instead of allowing a
  # single endpoint Dial operation to cancel sibling registrations.
  dial_string="$(asterisk_labeled_value "dialplan eval function PJSIP_DIAL_CONTACTS(${extension})" "Result")"
  route_source="PJSIP_DIAL_CONTACTS(${extension})"
  device_route=""
  device_aor=""
  if [ -z "$dial_string" ]; then
    device_route="$(asterisk_labeled_value "database get DEVICE ${extension}/dial" "Value")"
    if [[ "$device_route" =~ ^PJSIP/([A-Za-z0-9_.-]+)(/.*)?$ ]]; then
      device_aor="${BASH_REMATCH[1]}"
    fi
    if [ -n "$device_aor" ] && [ "$device_aor" != "$extension" ]; then
      dial_string="$(asterisk_labeled_value "dialplan eval function PJSIP_DIAL_CONTACTS(${device_aor})" "Result")"
      route_source="PJSIP_DIAL_CONTACTS(${device_aor}) from AstDB DEVICE/${extension}/dial"
    fi
  fi
  if [ -z "$dial_string" ]; then
    dial_string="$device_route"
    route_source="AstDB DEVICE/${extension}/dial fallback"
  fi
  if [ -z "$dial_string" ]; then
    dial_string="PJSIP/${extension}"
    route_source="PJSIP endpoint fallback"
  fi

  if ! pjsip_dial_string_supported "$dial_string"; then
    log "Registered endpoint $extension resolves through $route_source to a non-PJSIP paging route: $dial_string"
    log "The module will not rewrite DEVICE routes or SIP peers. Configure extension $extension as a PJSIP endpoint before installing."
    return 1
  fi
  if ! pjsip_dial_string_endpoints_available "$dial_string"; then
    log "Registered endpoint $extension resolves through $route_source, but one or more referenced PJSIP endpoint objects are unavailable."
    log "The module will not rewrite DEVICE routes or SIP peers. Repair the FreePBX endpoint route, then rerun the installer."
    return 1
  fi
  log "Registered endpoint $extension has a PJSIP-capable paging route with verified endpoint objects via $route_source."
}

endpoint_inventory_records() {
  local inventory_file="$1"
  python3 -I - "$inventory_file" <<'PY'
import json
import sys

supported_formats = {
    "yealink", "yealink_text", "yealink_image", "cisco", "poly", "grandstream",
    "fanvil", "snom", "aastra", "sangoma", "avaya", "vtech",
    "ale", "panasonic", "generic", "unknown",
}

with open(sys.argv[1], encoding="utf-8") as handle:
    inventory = json.load(handle)
if not isinstance(inventory, dict):
    raise SystemExit("endpoint inventory is not a JSON object")

for extension, details in sorted(inventory.items()):
    if not isinstance(extension, str) or not isinstance(details, dict):
        raise SystemExit("endpoint inventory contains an invalid entry")
    if not extension.isdigit():
        # The notification module intentionally targets numeric FreePBX
        # extensions, not trunks or arbitrary alphanumeric PJSIP objects.
        continue
    try:
        contacts = int(details.get("contacts") or 0)
    except (TypeError, ValueError):
        raise SystemExit(f"endpoint {extension} has an invalid contact count")
    phone_format = str(details.get("format") or "").strip().lower()
    formats = details.get("formats") or [phone_format]
    if not isinstance(formats, list):
        raise SystemExit(f"endpoint {extension} has an invalid format list")
    normalized_formats = [str(value or "").strip().lower() for value in formats]
    if contacts < 1:
        raise SystemExit(f"endpoint {extension} has no registered contacts")
    if phone_format not in supported_formats or any(value not in supported_formats for value in normalized_formats):
        raise SystemExit(f"endpoint {extension} has an unsupported phone format")
    user_agent = " ".join(str(details.get("user_agent") or "").replace("\t", " ").split())[:300]
    unique_formats = sorted(set(normalized_formats))
    unknown = "1" if phone_format == "unknown" or "unknown" in unique_formats else "0"
    mixed = "1" if len(unique_formats) > 1 else "0"
    print("\t".join((
        extension,
        phone_format,
        str(contacts),
        unknown,
        mixed,
        ",".join(unique_formats),
        user_agent,
    )))
PY
}

mixed_endpoint_visual_route_supported() {
  # Contact-specific delivery is preferred, but portable endpoint fan-out with
  # generic XML is a safe runtime fallback and must not abort installation.
  return 0
}

bootstrap_resource_capacity() {
  # Standalone installers need this before a release archive is available.
  # Keep this copy identical to the packaged helper; a regression checks parity.
  /usr/bin/python3 -I - "$@" <<'SLS_RESOURCE_CAPACITY_PY'
#!/usr/bin/env python3
"""Read-only admission checks for provisional combined PBX/SLS resource tiers.

These engineering allocations do not certify delivery throughput. Desktop
transport retains its separate stream limit and requires JSON-poll fallback.
"""
import argparse
import json
import os
from pathlib import Path

import re
import stat
import subprocess
import sys
import time
import types

GIB = 1024 ** 3
MIB = 1024 ** 2
TIERS = ((50, 2, 4), (100, 3, 5), (250, 4, 6), (500, 6, 8), (1000, 8, 12))
MAX_KERNEL_RESERVE = 256 * MIB
PHONE_BASELINE = 25
# 2026-10-02 private Asterisk: 25/50/100 PCM conference recipients used
# 0.058/0.133/0.242 cores and 35/41/52 MiB total RSS. Allow substantially more
# for SIP/SRTP, transcoding, caller setup bursts and normal PBX call traffic.
PHONE_RESOURCE_BASELINE = 100
PHONE_CONTACTS_PER_CPU = 200
PHONE_MEMORY_PER_CONTACT = 2 * MIB
PHONE_MEMORY_ROUNDING = 256 * MIB
# Reference measured 2026-10-02 on Debian12/CPython3.11/amd64 with every exact pin.
# The compressed wheel sizes are from the corresponding PyPI version JSON APIs.
PINNED_VENV_ALLOCATED = 217780224
PINNED_VENV_FILES = 4376
PINNED_WHEEL_BYTES = 77802965
PINNED_VERSIONS = {'pip': '26.2.1', 'setuptools': '84.0.0', 'wheel': '0.48.0',
                   'piper-tts': '1.8.0', 'onnxruntime': '1.30.0', 'numpy': '2.4.6',
                   'flatbuffers': '25.12.19', 'packaging': '26.3',
                   'pathvalidate': '3.3.1', 'protobuf': '7.36.2'}
VOICE_BYTES = {'en_US-lessac-low.onnx': 63201294, 'en_US-lessac-low.onnx.json': 4882,
               'en_US-lessac-medium.onnx': 63201294, 'en_US-lessac-medium.onnx.json': 4885,
               'en_US-amy-low.onnx': 63104526, 'en_US-amy-low.onnx.json': 4164,
               'en_US-ryan-low.onnx': 63104526, 'en_US-ryan-low.onnx.json': 4165,
               'es_ES-davefx-medium.onnx': 63201294,
               'es_ES-davefx-medium.onnx.json': 4817,
               'fr_FR-siwis-medium.onnx': 63201294,
               'fr_FR-siwis-medium.onnx.json': 4875,
               'de_DE-thorsten-low.onnx': 63104526,
               'de_DE-thorsten-low.onnx.json': 4159,
               'pt_BR-faber-medium.onnx': 63201294,
               'pt_BR-faber-medium.onnx.json': 4855}
MANAGED_TREES = {'runtime': '/usr/local/bin/sls_mass_notify',
                 'venv': '/usr/local/bin/sls_mass_notify/piper/venv',
                 'voices': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices',
                 'data': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin',
                 'config_backups': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/config-backups',
                 'audio': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts',
                 'module': '/var/www/html/admin/modules/slsmassnotifyserver',
                 'web': '/var/www/html/sls_mass_notify',
                 'assets': '/var/www/html/sls_mass_notify/assets',
                 'sip_api': '/var/www/html/api/sipnotify',
                 'control_api': '/var/www/html/api/sls-mass-notify', 'operator_portal': '/var/www/html/mass-notify'}
STATE_FILES = {'phone_ledger': 'phone-admission.json', 'weather_ledger': 'weather-delivery.json',
               'schedule_ledger': 'schedule-executions.json', 'config': 'mass-notifications.config'}
RECOVERY_RECORDINGS = tuple('/var/lib/asterisk/sounds/en/custom/' + name + '.wav' for name in (
    'SLS_Mass_Notify_Paging_Tone_Opening', 'SLS_Mass_Notify_Paging_Tone_Closing',
    'SLS_Mass_Notify_NWS_Alert', 'SLS_Mass_Notify_Lightning_Alert',
    'Paging_Tone_Opening', 'Paging_Tone_Closing', 'NWS_alert', 'Lightning_alert')) + tuple(
    MANAGED_TREES['data'] + '/sounds/tones/' + name + '.wav' for name in (
    'opening_Paging_Tone_Opening', 'closing_Paging_Tone_Closing', 'opening_NWS_alert', 'opening_Lightning_alert'))
PERSISTENT_PATHS = ('/', '/var/lib/asterisk', '/usr/local', '/var/www', '/var/log')
INSTALLER_JOURNAL = '/var/lib/sls-mass-notify-installer'


def rounded(value, unit=4096):
    return (int(value) + unit - 1) // unit * unit


def storage_requirements(measured=None, missing_voices=None, allocation_units=None, temporary_directory='/tmp', *, mode='install', payloads=None):
    """Explain each byte reservation; retained history is not a fixed quota.

    Installation workspaces may overlap bounded state updates/compaction.
    Shared synthesis admission and generated-media budgets are enforced by
    the runtime; audit/history retention still needs separate provisioning.
    """
    measured = measured or {}
    if mode not in ('runtime', 'prepare', 'install'):
        raise ValueError('Unsupported storage admission mode')
    if temporary_directory not in ('/tmp', '/var/tmp'):
        raise ValueError('Unsupported installation workspace')
    units = allocation_units or {}
    size = lambda name: int(measured.get(name, {}).get('allocated_bytes', 0))
    recovery_size = lambda name: max(size(name), int(measured.get(name, {}).get('logical_bytes', 0)))
    unit = lambda path: max(4096, int(units.get(path, 4096)))
    # Accepted releases contain at most2000 entries and50MiB regular-file data.
    payloads = payloads or {}
    payload = lambda path: (int(payloads[path]['bytes']) + int(payloads[path]['entries']) * unit(path)
                            if path in payloads else 50 * MIB + 2000 * unit(path))
    runtime, voices, data = (MANAGED_TREES[name] for name in ('runtime', 'voices', 'data'))
    reference_venv = PINNED_VENV_ALLOCATED + PINNED_VENV_FILES * (unit(runtime) - 4096)
    venv = max(reference_venv, size('venv'))
    voice_total = sum(rounded(value, unit(voices)) for value in VOICE_BYTES.values())
    model_temporary = rounded(max(VOICE_BYTES.values()), unit(voices))
    if missing_voices is None:
        missing_voices = voice_total
    # Phone/Weather 16 MiB; encrypted configuration 3 MiB; schedule reserve at least its10MiB backup
    #limit or its actual size. Up to25 queued jobs may write2MiB records.
    atomic_state = sum(max(limit * MIB, size(name)) for name, limit in
                       (('phone_ledger', 16), ('weather_ledger', 16), ('schedule_ledger', 10), ('config', 3))) + 25 * 2 * MIB
    # Retain 20 encrypted configuration backups, each bounded to 3 MiB.
    backup_growth = max(0, 20 * 3 * MIB - size('config_backups'))
    components = {
        INSTALLER_JOURNAL: {
            'protected_config_snapshot': 16 * MIB,
            'reviewed_previous_and_candidate_sources': 2 * payload(INSTALLER_JOURNAL),
            'previous_release_download_and_metadata': 64 * MIB + 16 * 1024 + 4096,
            'saved_candidate_archive_and_metadata': 52 * MIB + 16 * 1024 + 4096,
            'authenticated_bootstrap_helpers': payload(INSTALLER_JOURNAL),
            'prior_static_runtime_and_api_snapshot': sum(recovery_size(name) for name in
                ('runtime', 'assets', 'sip_api', 'control_api', 'operator_portal', 'recovery_recordings')) + 32 * MIB,
            'journal_metadata': 4 * MIB,
        },
        runtime: {'replacement_runtime': max(size('runtime'), venv + payload(runtime))},
        voices: {'missing_or_replacement_voices': max(int(missing_voices), model_temporary)},
        data: {'bounded_atomic_state_writes': atomic_state},
        MANAGED_TREES['config_backups']: {'remaining_bounded_config_backups': backup_growth},
        MANAGED_TREES['module']: {'new_module_payload': payload(MANAGED_TREES['module'])},
        #Independent target mounts each need room for a legal package payload.
        MANAGED_TREES['web']: {'public_asset_payload': payload(MANAGED_TREES['web'])},
        MANAGED_TREES['sip_api']: {'sip_api_payload': payload(MANAGED_TREES['sip_api'])},
        MANAGED_TREES['control_api']: {'control_api_payload': payload(MANAGED_TREES['control_api'])},
        MANAGED_TREES['operator_portal']: {'operator_portal_payload': payload(MANAGED_TREES['operator_portal'])},
        #Maintenance compacts logs sequentially, using backup+retained temporary.
        '/var/log': {'event_log_backup_and_retained_copy': 2 * 64 * MIB},
        '/var/lib/sls-mass-notify-trust': {'new_authenticated_generation': payload('/var/lib/sls-mass-notify-trust'),
                                         'stock_and_release_manifests': 8 * MIB},
        temporary_directory: {'release_download_and_metadata': 52 * MIB + 16 * 1024 + 4096,
                 'extracted_release': payload(temporary_directory), 'failed_release_recovery': payload(temporary_directory),
                 # Two retained signed upstream packages and one active GPG
                 # verification's signed/plain copies; each bounded to64MiB.
                 'upstream_package_verification': 4 * 64 * MIB,
                 'missing_signed_helper_repairs': payload(temporary_directory),
                 'quarantined_python_caches': 16 * MIB,
                 'pinned_wheel_downloads': rounded(PINNED_WHEEL_BYTES, unit(temporary_directory)),
                 'wheel_unpack_workspace': venv, 'pip_old_package_recovery': venv,
                 'one_voice_download': rounded(max(VOICE_BYTES.values()), unit(temporary_directory))},
    }
    if mode != 'install':
        components = {data: {'bounded_atomic_state_writes': atomic_state},
                      MANAGED_TREES['config_backups']: {'remaining_bounded_config_backups': backup_growth},
                      '/var/log': {'event_log_backup_and_retained_copy': 2 * 64 * MIB}}
        if mode == 'prepare':
            components[temporary_directory] = {'release_download_and_metadata': 52 * MIB,
                                               'extracted_release': payload(temporary_directory)}
        else:
            components[temporary_directory] = {}
    budgets = {path: sum(parts.values()) for path, parts in components.items()}
    inode_budgets = {data: 128, MANAGED_TREES['config_backups']: 24, '/var/log': 8, temporary_directory: 0}
    if mode == 'prepare':
        inode_budgets[temporary_directory] = 2016
    elif mode == 'install':
        for path in components:
            if path not in inode_budgets:
                inode_budgets[path] = int(payloads.get(path, {}).get('entries', 2000)) + 16
        inode_budgets[runtime] += max(PINNED_VENV_FILES, int(measured.get('venv', {}).get('files', 0)))
        inode_budgets[voices] = len(VOICE_BYTES) + 4
        inode_budgets[temporary_directory] = 2 * int(payloads.get(temporary_directory, {}).get('entries', 2000)) + 128
        inode_budgets[INSTALLER_JOURNAL] = 3 * int(payloads.get(INSTALLER_JOURNAL, {}).get('entries', 2000)) + 128 + sum(
            int(measured.get(name, {}).get('files', 0)) + int(measured.get(name, {}).get('directories', 0))
            for name in ('runtime', 'assets', 'sip_api', 'control_api', 'operator_portal', 'recovery_recordings'))
    fixed_names = ('runtime', 'voices', 'module', 'assets', 'sip_api', 'control_api')
    fixed = sum(size(name) for name in fixed_names)
    mutable = max(0, size('data') - size('voices')) + max(0, size('web') - size('assets'))
    return {'basis': 'authenticated_payload_and_filesystem_recovery_2026-10-06', 'mode': mode,
            'measured_fixed_allocation_bytes': fixed, 'measured_mutable_data_and_media_bytes': mutable,
            'measured_trees': measured, 'fresh_reference_voice_bytes': voice_total,
            'pinned_runtime_reference_bytes': reference_venv, 'pinned_wheel_download_bytes': PINNED_WHEEL_BYTES,
            'component_budgets_bytes': components, 'free_budgets_bytes': budgets,
            'inode_budgets': inode_budgets, 'allocation_units': units, 'payloads': payloads,
            'missing_voice_bytes': missing_voices,
            'temporary_directory': temporary_directory,
            'sls_persistent_free_bytes': sum(value for path, value in budgets.items() if path != temporary_directory),
            'temporary_free_bytes': budgets[temporary_directory],
            'additional_free_bytes': sum(budgets.values()),
            'steady_state_reserve_bytes': atomic_state + backup_growth + 2 * 64 * MIB,
            'retention_is_byte_bounded': False, 'generated_media_admission_is_byte_bounded': True,
            'synthesis_concurrency_is_enforced': True,
            'render_workspace_formula': {'raw_pcm_bytes': 'duration_seconds * voice_sample_rate * 2 + WAV header',
                                         'pbx_pcm_bytes': 'duration_seconds * 8000 * 2 + WAV header',
                                         'provisioning': 'Reserve raw audio, converted speech, final sequence and temporary sequence for each simultaneously rendering job. Add retained output separately.'},
            'retention_provisioning': 'Generated-media admission respects its configured cache and free-space budgets; active referenced media is preserved. Audit/history retention needs measured provisioning over its chosen window. Speech workspace is reserved before synthesis.'}


FREE_BUDGETS = storage_requirements()['free_budgets_bytes']


class ResourceProbeError(ValueError):
    """Only locally constructed, operator-safe diagnostics may reach the UI."""
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def measure_tree(path, deadline, maximum_entries=50000, *, cache=None):
    """Metadata only, without following directory/file links or printing names."""
    result = {'allocated_bytes': 0, 'logical_bytes': 0, 'files': 0, 'directories': 0, 'largest_file_bytes': 0}
    root = Path(path)
    if not root.exists():
        return result
    if root.is_symlink() or not root.is_dir():
        raise ValueError('An SLS storage root is not a real directory')
    cache = {} if cache is None else cache
    if str(root) in cache: return dict(cache[str(root)])
    seen, seen_directories, examined, pending = set(), set(), 0, [(root, False)]
    reports, children = {}, {}
    while pending:
        if time.monotonic() > deadline:
            raise ResourceProbeError('storage_scan_timeout', 'SLS storage measurement exceeded its time limit. Reduce retained files or resolve storage latency, then retry.')
        directory, complete = pending.pop()
        key = str(directory)
        if complete:
            report = reports[key]
            for child in children[key]:
                nested = reports.get(str(child), result)
                for name in ('allocated_bytes', 'logical_bytes', 'files', 'directories'):
                    report[name] += nested[name]
                report['largest_file_bytes'] = max(report['largest_file_bytes'], nested['largest_file_bytes'])
            cache[key] = dict(report)
            continue
        if key in cache:
            reports[key] = dict(cache[key]); continue
        try:
            metadata = directory.lstat()
        except FileNotFoundError:
            # Another worker may retire an already enumerated terminal directory.
            continue
        identity = (metadata.st_dev, metadata.st_ino)
        if not stat.S_ISDIR(metadata.st_mode) or identity in seen_directories:
            continue
        seen_directories.add(identity)
        reports[key] = {**result, 'directories': 1}
        children[key] = []
        pending.append((directory, True))
        try:
            with os.scandir(directory) as entries:
                for entry in entries:
                    examined += 1
                    if examined > maximum_entries:
                        raise ResourceProbeError('storage_scan_entry_limit', 'SLS storage measurement exceeded its ' + str(maximum_entries) + '-entry limit. Review retained files before retrying.')
                    if time.monotonic() > deadline:
                        raise ResourceProbeError('storage_scan_timeout', 'SLS storage measurement exceeded its time limit. Resolve storage latency or reduce retained files before retrying.')
                    try:
                        metadata = entry.stat(follow_symlinks=False)
                    except FileNotFoundError:
                        continue
                    if stat.S_ISDIR(metadata.st_mode):
                        child = Path(entry.path)
                        children[key].append(child); pending.append((child, False))
                        continue
                    identity = (metadata.st_dev, metadata.st_ino)
                    if not stat.S_ISREG(metadata.st_mode) or identity in seen:
                        continue
                    seen.add(identity)
                    report = reports[key]
                    report['allocated_bytes'] += metadata.st_blocks * 512
                    report['logical_bytes'] += metadata.st_size
                    report['largest_file_bytes'] = max(report['largest_file_bytes'], metadata.st_size)
                    report['files'] += 1
        except PermissionError as error:
            relative = directory.relative_to(root).as_posix()
            detail = str(root)
            if re.fullmatch(r'[A-Za-z0-9_./-]{1,120}', relative) and relative != '.':
                detail += '/' + relative
            raise ResourceProbeError('storage_directory_unreadable',
                'The PBX account cannot read SLS storage at ' + detail +
                '. Run Repair Installation to restore directory traversal/read permissions, then retry the resource check.') from error
        except OSError as error:
            raise ValueError('Unable to measure an SLS storage directory safely') from error
    return dict(cache.get(str(root), result))


def probe_storage(mode='install', payloads=None):
    deadline = time.monotonic() + (3 if mode == 'runtime' else 15)
    cache = {}
    measured = {name: measure_tree(path, deadline, cache=cache) for name, path in
                sorted(MANAGED_TREES.items(), key=lambda item: len(Path(item[1]).parts))}
    measured['recovery_recordings'] = {'allocated_bytes': 0, 'logical_bytes': 0, 'files': 0}
    for name in RECOVERY_RECORDINGS:
        try:
            metadata = Path(name).lstat()
        except FileNotFoundError:
            continue
        if stat.S_ISREG(metadata.st_mode):
            measured['recovery_recordings']['allocated_bytes'] += metadata.st_blocks * 512
            measured['recovery_recordings']['logical_bytes'] += metadata.st_size
            measured['recovery_recordings']['files'] += 1
    for key, name in STATE_FILES.items():
        try:
            metadata = Path(MANAGED_TREES['data'], name).lstat()
        except FileNotFoundError:
            continue
        if stat.S_ISREG(metadata.st_mode):
            measured[key] = {'allocated_bytes': metadata.st_blocks * 512, 'logical_bytes': metadata.st_size}
    units = {}
    for path in FREE_BUDGETS:
        existing = Path(path)
        while not existing.exists():
            existing = existing.parent
        units[path] = os.statvfs(existing).f_frsize
    missing = 0
    for name, expected in VOICE_BYTES.items():
        path = Path(MANAGED_TREES['voices'], name)
        try:
            metadata = path.lstat()
        except FileNotFoundError:
            metadata = None
        if metadata is None or not stat.S_ISREG(metadata.st_mode) or metadata.st_size != expected:
            missing += rounded(expected, max(4096, units[MANAGED_TREES['voices']]))
    return storage_requirements(measured, missing, units, mode=mode, payloads=payloads)


def measure_release_payloads(source):
    """Measure an authenticated root-private staging tree without executing it."""
    root = Path(source)
    for parent in (root, *root.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX:
            raise ResourceProbeError('candidate_source_unprotected', 'The authenticated candidate must be in a root-protected staging directory.')
    targets = [MANAGED_TREES[name] for name in ('module', 'runtime', 'web', 'sip_api', 'control_api', 'operator_portal')]
    targets += ['/tmp', '/var/lib/sls-mass-notify-trust']
    payloads = {target: {'bytes': 0, 'entries': 1} for target in targets}
    entries, total = 0, 0
    for directory, dirs, files in os.walk(root, followlinks=False):
        for name in (*dirs, *files):
            path = Path(directory, name); info = path.lstat(); entries += 1
            if (entries > 2000 or info.st_uid != 0 or info.st_mode & 0o022
                    or not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode) and info.st_nlink == 1)):
                raise ResourceProbeError('candidate_source_unsafe', 'The authenticated candidate contains an unsafe entry or exceeds its 2000-entry bound.')
            relative = path.relative_to(root).as_posix()
            size = info.st_size if stat.S_ISREG(info.st_mode) else 0
            total += size
            if total > 50 * MIB: raise ResourceProbeError('candidate_source_oversized', 'The candidate exceeds the 50 MiB payload bound.')
            destinations = [MANAGED_TREES['module'], '/tmp', '/var/lib/sls-mass-notify-trust']
            if relative.startswith('bin/'): destinations.append(MANAGED_TREES['runtime'])
            elif relative.startswith('api/sipnotify/'): destinations.append(MANAGED_TREES['sip_api'])
            elif relative.startswith('api/sls-mass-notify/'): destinations.append(MANAGED_TREES['control_api'])
            elif relative.startswith('portal/'): destinations.append(MANAGED_TREES['operator_portal'])
            elif relative.startswith('assets/'): destinations.append(MANAGED_TREES['web'])
            for destination in destinations:
                payloads[destination]['bytes'] += size; payloads[destination]['entries'] += 1
    return payloads


def read_limit(path):
    try:
        return path.read_text(encoding='ascii').strip()
    except FileNotFoundError:
        return None


def cgroup_directories():
    """Inspect the current cgroup and all visible parents, including v1 hosts."""
    entries = []
    for line in Path('/proc/self/cgroup').read_text().splitlines():
        _, controllers, relative = line.split(':', 2)
        parts = Path(relative).parts
        if '..' in parts or not relative.startswith('/'):
            raise ValueError('Invalid cgroup membership')
        if not controllers:
            entries.append((Path('/sys/fs/cgroup'), relative, 'v2'))
        else:
            for controller in controllers.split(','):
                if controller in {'memory', 'cpu'}:
                    root = Path('/sys/fs/cgroup') / controller
                    if controller == 'cpu' and not root.exists():
                        root = Path('/sys/fs/cgroup/cpu,cpuacct')
                    entries.append((root, relative, controller))
    for root, relative, kind in entries:
        current = root / relative.lstrip('/')
        while True:
            if current.is_dir():
                yield current, kind
            if current == root:
                break
            current = current.parent


def probe_compute():
    cpu = float(len(os.sched_getaffinity(0)))
    memory_text = Path('/proc/meminfo').read_text(encoding='ascii')
    match = re.search(r'^MemTotal:\s+(\d+)\s+kB$', memory_text, re.M)
    if not match or cpu < 1:
        raise ValueError('CPU or physical memory information is unavailable')
    memory = int(match.group(1)) * 1024
    for directory, kind in cgroup_directories():
        memory_value = read_limit(directory / ('memory.max' if kind == 'v2' else 'memory.limit_in_bytes')) if kind in {'v2', 'memory'} else None
        if memory_value is not None and memory_value != 'max':
            limit = int(memory_value)
            if 0 < limit < 2 ** 60:
                memory = min(memory, limit)
        if kind == 'v2':
            value = read_limit(directory / 'cpu.max')
            if value:
                quota, period = value.split()
                if quota != 'max':
                    cpu = min(cpu, int(quota) / int(period))
        elif kind == 'cpu':
            quota = read_limit(directory / 'cpu.cfs_quota_us')
            period = read_limit(directory / 'cpu.cfs_period_us')
            if quota is not None and period is not None and int(quota) > 0:
                cpu = min(cpu, int(quota) / int(period))
    if memory <= 0 or cpu <= 0:
        raise ValueError('Invalid effective resource limits')
    return cpu, memory


def filesystem_types():
    mounts = []
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        before, after = line.split(' - ', 1)
        mount = re.sub(r'\\([0-7]{3})', lambda match: chr(int(match.group(1), 8)), before.split()[4])
        mounts.append((mount.rstrip('/') or '/', after.split()[0]))
    return sorted(mounts, key=lambda item: len(item[0]), reverse=True)


def probe_filesystems(free_budgets=None, inode_budgets=None):
    free_budgets = FREE_BUDGETS if free_budgets is None else free_budgets
    mounts = filesystem_types()
    devices = {}
    for path in dict.fromkeys((*PERSISTENT_PATHS, *free_budgets)):
        existing = Path(path)
        while not existing.exists():
            existing = existing.parent
        actual = str(existing.resolve())
        metadata = existing.stat()
        usage = os.statvfs(existing)
        kind = next((kind for mount, kind in mounts if actual == mount or actual.startswith(mount.rstrip('/') + '/')), '')
        persistent = path not in ('/tmp', '/var/tmp') and kind not in {'tmpfs', 'ramfs', 'devtmpfs'}
        record = devices.setdefault(metadata.st_dev, {
            'device': str(metadata.st_dev), 'paths': [], 'total_bytes': usage.f_blocks * usage.f_frsize,
            'available_bytes': usage.f_bavail * usage.f_frsize, 'required_free_bytes': 0, 'persistent': False,
            'read_only': False, 'inodes_exhausted': False,
            'filesystem_type': kind, 'required_inodes': 0,
            'available_inodes': getattr(usage, 'f_favail', None) if getattr(usage, 'f_files', 0) > 0 else None,
            'volatile_targets': [],
        })
        record['paths'].append(path)
        # Shared mounts and bind mounts must not count the same bytes twice.
        record['available_bytes'] = min(record['available_bytes'], usage.f_bavail * usage.f_frsize)
        record['total_bytes'] = min(record['total_bytes'], usage.f_blocks * usage.f_frsize)
        if getattr(usage, 'f_files', 0) > 0:
            record['available_inodes'] = min(record['available_inodes'], usage.f_favail) if record['available_inodes'] is not None else usage.f_favail
        record['required_free_bytes'] += free_budgets.get(path, 0)
        record['required_inodes'] += (inode_budgets or {}).get(path, 0)
        if free_budgets.get(path, 0) and path not in ('/tmp', '/var/tmp') and not persistent:
            record['volatile_targets'].append(path)
        if free_budgets.get(path, 0):
            record['read_only'] = record['read_only'] or bool(getattr(usage, 'f_flag', 0) & os.ST_RDONLY)
            # Filesystems without a fixed inode table may report zero for both.
            record['inodes_exhausted'] = record['inodes_exhausted'] or (
                getattr(usage, 'f_files', 0) > 0 and getattr(usage, 'f_favail', None) == 0)
        record['persistent'] = record['persistent'] or persistent
    return list(devices.values())


def trusted_temporary_directory(path):
    if path not in ('/tmp', '/var/tmp'):
        return False
    try:
        for directory in (Path(path), *Path(path).parents):
            info = directory.lstat()
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid != 0
                    or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX)):
                return False
        return True
    except OSError:
        return False


def probe_storage_filesystems(storage, temporary_directory=None):
    """Use persistent scratch space when a small /tmp cannot hold the install.

    All reservations still apply to their actual filesystem. Moving scratch
    cannot make an insufficient data/runtime filesystem eligible.
    """
    choices = (temporary_directory,) if temporary_directory else ('/tmp', '/var/tmp')
    first = None
    for path in choices:
        if not trusted_temporary_directory(path):
            continue
        if path == '/tmp':
            candidate = storage
        else:
            units = {**storage.get('allocation_units', {}), path: os.statvfs(path).f_frsize}
            payloads = dict(storage.get('payloads', {}))
            if '/tmp' in payloads: payloads[path] = payloads.pop('/tmp')
            candidate = storage_requirements(storage.get('measured_trees'), storage.get('missing_voice_bytes'), units, path,
                mode=storage.get('mode', 'install'), payloads=payloads)
        filesystems = probe_filesystems(candidate['free_budgets_bytes'], candidate.get('inode_budgets'))
        if first is None:
            first = (candidate, filesystems)
        scratch_volatile = any(path in row['paths'] and row.get('filesystem_type') in {'tmpfs', 'ramfs', 'devtmpfs'} for row in filesystems)
        if scratch_volatile and temporary_directory is None and path == '/tmp' and candidate['temporary_free_bytes']:
            continue
        if all(row['available_bytes'] >= row['required_free_bytes'] and not row.get('read_only')
               and not row.get('inodes_exhausted') and not row.get('volatile_targets')
               and (row.get('available_inodes') is None or row['available_inodes'] >= row.get('required_inodes', 0)) for row in filesystems):
            return candidate, filesystems
    if first is None:
        raise ResourceProbeError('temporary_directory_unavailable',
            'Neither /tmp nor /var/tmp is a safe root-owned temporary directory. Restore the standard directory ownership and sticky permissions before installing.')
    return first


def configured_limits(path):
    """Read only the capacity fields; never echo configuration or credentials."""
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('Configuration path must be absolute')
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for component in path.parts[1:-1]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            os.close(parent)
            parent = child
        descriptor = os.open(path.name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=parent)
    finally:
        os.close(parent)
    with os.fdopen(descriptor, 'rb') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > 16 * 1024 * 1024:
            raise ValueError('Configuration is not a bounded private regular file')
        raw = handle.read(16 * 1024 * 1024 + 1)
    if len(raw) > 16 * 1024 * 1024:
        raise ValueError('Configuration exceeded its size limit')
    value = json.loads(raw)
    if isinstance(value, dict) and any(field in value for field in ('format', 'ciphertext', 'key_id', 'nonce')):
        if value.get('format') != 'sls-mass-notify-config-aes256gcm-v1':
            raise ValueError('The protected configuration encryption format is unsupported or damaged')
        helper = Path(__file__).resolve().with_name('sls_config_crypto.py')
        if not helper.is_file():
            helper = Path('/usr/local/bin/sls_mass_notify/sls_config_crypto.py')
        for protected in [helper, *helper.parents][:-1]:
            metadata = protected.lstat()
            if metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not (stat.S_ISDIR(metadata.st_mode) and metadata.st_mode & stat.S_ISVTX)) or stat.S_ISLNK(metadata.st_mode):
                raise ValueError('Installed encryption helper is not protected')
        sys.dont_write_bytecode = True
        descriptor = os.open(helper, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            opened = os.fstat(descriptor)
            if not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_mode & 0o022 or opened.st_nlink != 1 or opened.st_size > 256 * 1024:
                raise ValueError('Installed encryption helper is unsafe')
            chunks = []
            while True:
                chunk = os.read(descriptor, 65536)
                if not chunk: break
                chunks.append(chunk)
                if sum(map(len, chunks)) > 256 * 1024: raise ValueError('Installed encryption helper exceeded its size limit')
            body = b''.join(chunks)
            after = os.fstat(descriptor)
            if len(body) != opened.st_size or (opened.st_ino, opened.st_size, opened.st_mtime_ns, opened.st_ctime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise ValueError('Installed encryption helper changed during inspection')
        finally: os.close(descriptor)
        module = types.ModuleType('sls_config_crypto'); module.__file__ = str(helper)
        try: exec(compile(body, str(helper), 'exec'), module.__dict__)
        except ImportError as error:
            raise ResourceProbeError('config_crypto_dependency_missing', 'Encrypted settings require the system python3-cryptography package. Restore that prerequisite; existing settings and encryption keys must be preserved.') from error
        value = module.decode_config(raw)
    if not isinstance(value, dict):
        raise ValueError('Configuration must be an object')
    capacities = {key: value.get(key, default) for key, default in
                  (('desktop_client_limit', 25), ('phone_device_limit', PHONE_BASELINE))}
    if any(type(limit) is not int or not 1 <= limit <= 1000 for limit in capacities.values()):
        raise ValueError('Invalid notification capacity')
    return capacities


def rounded_phone_capacity(count):
    """Retain 25 for small installations, then round up in blocks of 50."""
    if type(count) is not int or count < 0:
        raise ValueError('Invalid detected phone count')
    if count > 1000:
        raise ResourceProbeError('phone_inventory_exceeds_limit',
            'More than 1000 internal phone contacts were detected. SLS supports at most 1000 simultaneous contacts; review the device inventory before installation.')
    return PHONE_BASELINE if count <= PHONE_BASELINE else rounded(count, 50)


def internal_phone_inventory(device_output, contact_output):
    """Count FreePBX PJSIP devices and their contacts, excluding trunk AORs.

    An offline configured device reserves one slot. Shared endpoint aliases do
    not count twice; several contacts on the same endpoint each count once.
    Complete CLI summaries are required so truncated output cannot undercount.
    Only aggregate counts leave this function, never addresses or device names.
    """
    lines = [line.strip() for line in device_output.splitlines() if line.strip()]
    rows = [line for line in lines if line.startswith('/DEVICE/')]
    if not lines or not re.fullmatch(r'[0-9]+ results found\.', lines[-1]) or int(lines[-1].split()[0]) != len(rows):
        raise ValueError('Incomplete FreePBX device inventory')
    endpoints = set()
    for line in rows:
        match = re.fullmatch(r'/DEVICE/[0-9]+/dial\s*:\s*(.*)', line)
        if not match or not match[1].startswith('PJSIP/'):
            continue
        endpoint = re.fullmatch(r'PJSIP/([A-Za-z0-9_.-]{1,80})', match[1])
        if not endpoint:
            raise ValueError('Unsupported internal PJSIP device route')
        endpoints.add(endpoint[1])
    if not endpoints:
        return {'configured_pjsip_devices': 0, 'registered_phone_contacts': 0, 'detected_phone_contacts': 0}
    contact_lines = [line.strip() for line in contact_output.splitlines() if line.strip()]
    summaries = [line for line in contact_lines if re.fullmatch(r'Objects found: [0-9]+', line)
                 or line in ('No objects found.', 'No Contacts found.')]
    if len(summaries) != 1:
        raise ValueError('Incomplete PJSIP contact inventory')
    expected = int(summaries[0].split()[-1]) if summaries[0].startswith('Objects found:') else 0
    contacts, counts = [], {endpoint: 0 for endpoint in endpoints}
    for line in contact_lines:
        if not line.startswith('Contact:'):
            continue
        fields = line.split()
        if len(fields) > 1 and fields[1].startswith('<'):
            continue
        if len(fields) != 5 or '/' not in fields[1] or not re.fullmatch(r'[a-fA-F0-9]{8,64}', fields[2]):
            raise ValueError('Unrecognized PJSIP contact inventory row')
        endpoint = fields[1].split('/', 1)[0]
        contacts.append((endpoint, fields[2]))
        if endpoint in counts:
            counts[endpoint] += 1
    if len(contacts) != expected or len(set(contacts)) != len(contacts):
        raise ValueError('Incomplete or duplicate PJSIP contact inventory')
    return {'configured_pjsip_devices': len(endpoints), 'registered_phone_contacts': sum(counts.values()),
            'detected_phone_contacts': sum(max(1, count) for count in counts.values())}


def detect_internal_phones():
    def command(value):
        result = subprocess.run(['/usr/sbin/asterisk', '-rx', value], capture_output=True,
                                text=True, timeout=3, check=False)
        if result.returncode != 0 or len(result.stdout) > 2 * MIB:
            raise ValueError('Asterisk inventory command failed')
        return result.stdout
    try:
        devices = command('database show DEVICE')
        # An empty DEVICE family needs no contact query. This also permits an
        # otherwise healthy new PBX whose PJSIP modules have no endpoints yet.
        empty = internal_phone_inventory(devices, 'Objects found: 0\n')
        return internal_phone_inventory(devices, command('pjsip show contacts')) if empty['configured_pjsip_devices'] else empty
    except (OSError, ValueError, subprocess.TimeoutExpired) as error:
        raise ResourceProbeError('phone_inventory_unavailable',
            'The internal phone inventory could not be verified. Confirm Asterisk is running and its DEVICE database and PJSIP contact commands are available, then retry installation. No phone capacity was guessed.') from error


def capacity_requirements(requested, phone_requested):
    if type(requested) is not int or not 1 <= requested <= 1000:
        raise ValueError('Desktop capacity must be between 1 and 1000')
    if type(phone_requested) is not int or not 1 <= phone_requested <= 1000:
        raise ValueError('Phone capacity must be between 1 and 1000')
    tier_limit, desktop_cpu, desktop_memory = next(tier for tier in TIERS if requested <= tier[0])
    extra_contacts = max(0, phone_requested - PHONE_RESOURCE_BASELINE)
    phone_cpu = (extra_contacts + PHONE_CONTACTS_PER_CPU - 1) // PHONE_CONTACTS_PER_CPU
    phone_memory = rounded(extra_contacts * PHONE_MEMORY_PER_CONTACT, PHONE_MEMORY_ROUNDING)
    return desktop_cpu + phone_cpu, desktop_memory * GIB + phone_memory, {
        'combined_baseline': {'cpu_count': 2, 'memory_bytes': 4 * GIB,
                              'desktop_clients': 50, 'phone_contacts': PHONE_RESOURCE_BASELINE},
        'desktop_growth': {'tier_limit': tier_limit, 'cpu_count': desktop_cpu - 2,
                           'memory_bytes': (desktop_memory - 4) * GIB},
        'phone_growth': {'contacts_over_baseline': extra_contacts, 'cpu_count': phone_cpu,
                         'memory_bytes': phone_memory},
    }


def minimum_reported_memory(hardware, nominal):
    """Allow bounded kernel reservations, never a lower enforced cgroup quota."""
    quota = hardware.get('memory_limit_bytes')
    if isinstance(quota, int) and 0 < quota < nominal:
        return nominal
    return nominal - min(MAX_KERNEL_RESERVE, nominal // 16)


def probe_memory_limit():
    limits = []
    for directory, kind in cgroup_directories():
        if kind not in {'v2', 'memory'}: continue
        value = read_limit(directory / ('memory.max' if kind == 'v2' else 'memory.limit_in_bytes'))
        if value not in (None, 'max'):
            limit = int(value)
            if 0 < limit < 2 ** 60: limits.append(limit)
    return min(limits) if limits else None


def evaluate(hardware, requested, phone_requested=PHONE_BASELINE):
    cpu, memory_bytes, components = capacity_requirements(requested, phone_requested)
    required_memory = minimum_reported_memory(hardware, memory_bytes)
    errors = []
    storage = hardware.get('storage', storage_requirements())
    scratch_memory = sum(item['required_free_bytes'] for item in hardware['filesystems']
        if item.get('filesystem_type') in {'tmpfs', 'ramfs', 'devtmpfs'}
        and storage.get('temporary_directory', '/tmp') in item['paths'])
    required_memory += scratch_memory

    def require(code, message, actual, required):
        if actual < required:
            errors.append({'code': code, 'message': message, 'actual': actual, 'required': required})

    require('cpu_insufficient',
            f'{cpu} effective CPU cores are required for {requested} desktops and {phone_requested} phone contacts; {hardware["effective_cpu_count"]:g} are allocated. Add CPU allocation or lower Desktop Capacity or Phone Capacity.',
            hardware['effective_cpu_count'], cpu)
    advice = ('This is the minimum combined PBX/SLS tier; reducing device capacities will not lower its RAM baseline.'
              if memory_bytes == 4 * GIB else 'Add RAM or lower Desktop Capacity or Phone Capacity.')
    require('memory_insufficient',
            f'{memory_bytes / GIB:g} GiB nominal RAM is required for {requested} desktops and {phone_requested} phone contacts; {hardware["effective_memory_bytes"] / GIB:.2f} GiB is usable and {required_memory / GIB:.2f} GiB is required after kernel reservations and scratch space. {advice}',
            hardware['effective_memory_bytes'], required_memory)
    disks_ok = True
    for item in hardware['filesystems']:
        before = len(errors)
        destinations = [path for path in item['paths'] if storage['free_budgets_bytes'].get(path, 0)] or item['paths']
        location = ', '.join(destinations)
        if item.get('read_only'):
            errors.append({'code': 'storage_read_only', 'message': 'The filesystem containing ' + location + ' is read-only. Restore its writable mount before installing; available bytes cannot make a read-only target usable.', 'actual': None, 'required': None})
        if item.get('inodes_exhausted'):
            errors.append({'code': 'storage_inodes_exhausted', 'message': 'The filesystem containing ' + location + ' has no available file entries (inodes). Free file entries or expand that filesystem before installing.', 'actual': 0, 'required': 1})
        elif item.get('available_inodes') is not None:
            require('storage_inodes_insufficient', 'The filesystem containing ' + location + ' lacks the file entries required for this operation. Free inodes or expand the filesystem.',
                    item['available_inodes'], item.get('required_inodes', 0))
        volatile = item.get('volatile_targets') or (item.get('persistent') is False and any(path not in ('/tmp', '/var/tmp') and storage['free_budgets_bytes'].get(path, 0) for path in item['paths']))
        if volatile:
            errors.append({'code': 'storage_not_persistent', 'message': 'The filesystem containing ' + location + ' is volatile. Configuration, delivery history and installed runtime require persistent storage.', 'actual': None, 'required': None})
        require('free_space_insufficient',
                f'{item["required_free_bytes"] / MIB:.2f} MiB of additional SLS free space is required on the filesystem containing {location}; {item["available_bytes"] / MIB:.2f} MiB is available. Free space or expand this filesystem.',
                item['available_bytes'], item['required_free_bytes'])
        disks_ok = disks_ok and len(errors) == before
    eligible = 0
    eligible_phone = 0
    if disks_ok:
        for maximum, _, _ in TIERS:
            tier_cpu, tier_memory, _ = capacity_requirements(maximum, phone_requested)
            if hardware['effective_cpu_count'] >= tier_cpu and hardware['effective_memory_bytes'] >= minimum_reported_memory(hardware, tier_memory) + scratch_memory:
                eligible = maximum
        # Phone capacity is a continuous integer policy, not the desktop tiers.
        for phone_limit in range(1, 1001):
            phone_cpu, phone_memory, _ = capacity_requirements(requested, phone_limit)
            if hardware['effective_cpu_count'] >= phone_cpu and hardware['effective_memory_bytes'] >= minimum_reported_memory(hardware, phone_memory) + scratch_memory:
                eligible_phone = phone_limit
    checks = {'cpu': {'ok': hardware['effective_cpu_count'] >= cpu,
                      'actual': hardware['effective_cpu_count'], 'required': cpu},
              'memory': {'ok': hardware['effective_memory_bytes'] >= required_memory,
                         'actual': hardware['effective_memory_bytes'], 'required': required_memory}}
    temporary = storage.get('temporary_directory', '/tmp')
    for name, paths in (('storage', set(storage['free_budgets_bytes']) - {temporary}), ('temporary', {temporary})):
        filesystems = [item for item in hardware['filesystems'] if paths.intersection(item['paths'])]
        covered = set(path for item in filesystems for path in item['paths'])
        checks[name] = {'ok': all(item['available_bytes'] >= item['required_free_bytes'] and not item.get('read_only')
                                and not item.get('inodes_exhausted') and not item.get('volatile_targets')
                                and (item.get('available_inodes') is None or item['available_inodes'] >= item.get('required_inodes', 0))
                                and not (item.get('persistent') is False and any(path not in ('/tmp', '/var/tmp') and storage['free_budgets_bytes'].get(path, 0) for path in item['paths'])) for item in filesystems)
                       if paths <= covered else None,
                       'filesystems': [{'paths': item['paths'], 'actual': item['available_bytes'],
                                        'required': item['required_free_bytes']} for item in filesystems]}
    return {
        'schema': 1, 'requested_limit': requested, 'eligible_limit': eligible, 'hardware': hardware,
        'requested_phone_limit': phone_requested, 'eligible_phone_limit': eligible_phone,
        'requirements': {'cpu_count': cpu, 'memory_bytes': memory_bytes,
                         'minimum_reported_memory_bytes': required_memory,
                         'components': components, 'disk_policy': 'additional_sls_free_headroom',
                         'sls_persistent_free_bytes': storage['sls_persistent_free_bytes'], 'temporary_free_bytes': storage['temporary_free_bytes'],
                         'free_budgets_bytes': storage['free_budgets_bytes'], 'storage': storage,
                         'reporting_allowance_percent': (memory_bytes - minimum_reported_memory(hardware, memory_bytes)) * 100 / memory_bytes,
                         'kernel_reserve_bytes': memory_bytes - minimum_reported_memory(hardware, memory_bytes),
                         'temporary_memory_bytes': scratch_memory},
        'assumptions': {'load_certified': False, 'phone_baseline_contacts': PHONE_BASELINE,
                        'additional_phone_contacts_per_cpu_core': PHONE_CONTACTS_PER_CPU,
                        'additional_phone_memory_bytes_per_contact': PHONE_MEMORY_PER_CONTACT,
                        'phone_memory_rounding_bytes': PHONE_MEMORY_ROUNDING, 'desktop_sse_limit': 32,
                        'included_phone_contacts': PHONE_RESOURCE_BASELINE,
                        'synthesis_concurrency_limited_by_this_check': False,
                        'whole_volume_size_is_enforced': False},
        'errors': errors,
        'resource_checks': checks,
        'warnings': ['Provisional combined PBX/SLS allocation; not load-certified. Phone growth reserves capacity for registered contacts and Local/Page overhead, not measured phones-per-core throughput. Runtime speech admission separately enforces its configured concurrency and media budget.',
                     f'SLS measured fixed files occupy {storage["measured_fixed_allocation_bytes"] / MIB:.1f} MiB. Additional {storage.get("mode", "install")} workspace requires {storage["additional_free_bytes"] / MIB:.1f} MiB across the listed filesystems, including {storage["steady_state_reserve_bytes"] / MIB:.1f} MiB for bounded writes/compaction. Retention and concurrent speech require additional measured provisioning; these are not storage quotas or a guarantee for an arbitrary workload.',
                     'Desktop clients above the stream limit require cursor-aware JSON polling. Existing PBX calls, codecs, recording, voicemail, backups, and long retention need separate resource budgets.'],
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--config', type=Path)
    parser.add_argument('--desktop-limit', type=int)
    parser.add_argument('--temporary-directory', choices=('/tmp', '/var/tmp'))
    parser.add_argument('--mode', choices=('runtime', 'prepare', 'install'), default='runtime')
    parser.add_argument('--candidate-source', type=Path, help='Authenticated root-private candidate payload for installation budgets')
    phone_options = parser.add_mutually_exclusive_group()
    phone_options.add_argument('--phone-limit', type=int)
    phone_options.add_argument('--auto-phone-limit', action='store_true',
                               help='Detect internal phones on a fresh install; retain saved capacities on upgrades')
    options = parser.parse_args(argv)
    requested = options.desktop_limit
    phone_requested = options.phone_limit
    stage = 'configuration'
    selection = None
    try:
        saved = configured_limits(options.config) if options.config else {}
        if requested is None:
            requested = saved.get('desktop_client_limit', 25)
        if phone_requested is None:
            phone_requested = saved.get('phone_device_limit', PHONE_BASELINE)
        if options.auto_phone_limit and not saved:
            stage = 'internal phone inventory'
            selection = detect_internal_phones()
            phone_requested = rounded_phone_capacity(selection['detected_phone_contacts'])
            selection.update({'source': 'internal_pjsip_inventory', 'rounded_phone_capacity': phone_requested})
        stage = 'CPU and memory'
        cpu, memory = probe_compute()
        stage = 'SLS storage'
        payloads = measure_release_payloads(options.candidate_source) if options.candidate_source else None
        storage = probe_storage(options.mode, payloads)
        stage = 'filesystem free space'
        storage, filesystems = probe_storage_filesystems(storage, options.temporary_directory)
        result = evaluate({'effective_cpu_count': cpu, 'effective_memory_bytes': memory,
                           'memory_limit_bytes': probe_memory_limit(), 'filesystems': filesystems, 'storage': storage}, requested, phone_requested)
        if selection is not None:
            result['auto_phone_selection'] = selection
    except (OSError, ValueError, ZeroDivisionError, StopIteration) as error:
        code = error.code if isinstance(error, ResourceProbeError) else 'resource_probe_failed'
        message = str(error) if isinstance(error, ResourceProbeError) else (
            'The ' + stage + ' resource check could not be completed. Check the protected configuration and storage permissions, then run Repair Installation before increasing capacity.')
        result = {'schema': 1, 'requested_limit': requested, 'eligible_limit': 0, 'hardware': {}, 'requirements': {},
                  'requested_phone_limit': phone_requested, 'eligible_phone_limit': 0,
                  'errors': [{'code': code, 'message': message, 'actual': None, 'required': None}], 'warnings': []}
        print(json.dumps(result, sort_keys=True))
        return 3
    print(json.dumps(result, sort_keys=True))
    return 2 if result['errors'] else 0


if __name__ == '__main__':
    sys.exit(main())
SLS_RESOURCE_CAPACITY_PY
}

preflight_hardware_requirements() {
  local report status
  local -a arguments=(--check --mode prepare --desktop-limit 25 --auto-phone-limit)
  if [ -e "$CONFIG_FILE" ] || [ -L "$CONFIG_FILE" ]; then
    arguments=(--check --mode prepare --config "$CONFIG_FILE")
  fi
  if report="$(bootstrap_resource_capacity "${arguments[@]}")"; then
    select_install_temp_root "$report" || return 1
    if [ ! -e "$CONFIG_FILE" ]; then
      remember_fresh_phone_capacity "$report" || return 1
    fi
    log "Combined PBX/SLS CPU/RAM and dedicated SLS free-space headroom verified (provisional allocation; not a load certification)."
    printf '%s\n' "$report" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
    return 0
  else
    status=$?
  fi
  log "Resource admission failed before optional dependency or module changes."
  printf '%s\n' "$report" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
  /usr/bin/python3 -I -c '
import json, sys
try:
    result = json.loads(sys.argv[1])
    for item in result.get("errors", [])[:20]:
        print("[" + str(item.get("code", "resource_error"))[:80] + "] " + str(item.get("message", "Review the installer log."))[:1000])
except (ValueError, TypeError, AttributeError):
    print("The resource probe did not return valid diagnostics. Review the installer log before retrying.")
' "$report"
  return "$status"
}

select_install_temp_root() {
  local directory
  directory="$(/usr/bin/python3 -I -c '
import json, os, stat, sys
path = json.loads(sys.argv[1]).get("requirements", {}).get("storage", {}).get("temporary_directory", "/tmp")
if path not in ("/tmp", "/var/tmp"): raise SystemExit(1)
fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    for name in path.split("/")[1:]:
        child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        os.close(fd); fd = child
        info = os.fstat(fd)
        if info.st_uid != 0 or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX): raise SystemExit(1)
finally: os.close(fd)
print(path)
' "$1")" || { log 'The selected temporary directory is unsafe; no module or dependency changes were started.'; return 1; }
  INSTALL_TEMP_ROOT="$directory"
  export TMPDIR="$directory"
  log "Installer workspaces: $INSTALL_TEMP_ROOT (free space verified on its actual filesystem)."
}

remember_fresh_phone_capacity() {
  local capacity
  capacity="$(/usr/bin/python3 -I -c '
import json, sys
report = json.loads(sys.argv[1])
selection = report["auto_phone_selection"]
count, limit = selection["detected_phone_contacts"], report["requested_phone_limit"]
assert type(count) is int and 0 <= count <= 1000
assert type(limit) is int and limit == (25 if count <= 25 else (count + 49) // 50 * 50)
assert selection["rounded_phone_capacity"] == limit and not report["errors"]
print(limit)
' "$1")" || { log 'The detected phone capacity could not be verified; no default was guessed.'; return 1; }
  FRESH_PHONE_CAPACITY="$capacity"
  log "Fresh-install phone capacity: $FRESH_PHONE_CAPACITY contacts (25 minimum, rounded up in blocks of 50; resources verified)."
}

verify_staged_hardware_requirements() {
  local helper="$STAGING_DIR/$MODULE/bin/sls_mass_notify/sls_resource_capacity.py"
  local report
  local -a arguments=(--check --desktop-limit 25 --auto-phone-limit)
  if [ -e "$CONFIG_FILE" ]; then
    arguments=(--check --config "$CONFIG_FILE")
  fi
  report="$(/usr/bin/python3 -I "$helper" "${arguments[@]}" --mode install --candidate-source "$STAGING_DIR/$MODULE" --temporary-directory "$INSTALL_TEMP_ROOT" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}")" || {
    log "$report"
    log "This PBX does not meet the configured desktop/phone capacities or SLS free-space requirements. See $LOG_FILE."
    return 1
  }
  printf '%s\n' "$report" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
  if [ ! -e "$CONFIG_FILE" ]; then
    remember_fresh_phone_capacity "$report"
  fi
}

preflight_platform() {
  local apache_module apache_modules utility
  # Runtime and dialplan paths are conventional FreePBX paths. Detect a
  # relocated installation explicitly instead of creating a second, unused tree.
  /usr/bin/timeout 20 /usr/sbin/runuser -u asterisk -- /usr/bin/php <<'PHP' || {
<?php
require '/etc/freepbx.conf';
$expected = ['AMPWEBROOT'=>'/var/www/html', 'ASTETCDIR'=>'/etc/asterisk',
    'ASTSPOOLDIR'=>'/var/spool/asterisk', 'ASTVARLIBDIR'=>'/var/lib/asterisk',
    'AMPASTERISKUSER'=>'asterisk', 'AMPASTERISKGROUP'=>'asterisk'];
foreach ($expected as $key => $path) {
    $actual = rtrim((string)($amp_conf[$key] ?? ''), '/');
    if ($actual !== $path) {
        fwrite(STDERR, 'Unsupported FreePBX layout: ' . $key . ' must be ' . $path . '. No alternate runtime tree will be created.' . PHP_EOL);
        exit(1);
    }
}
exit(0);
PHP
    log "This FreePBX layout requires an explicitly supported path adapter; installation stopped before activation."
    exit 1
  }
  for utility in timeout runuser flock readlink; do
    command -v "$utility" >/dev/null || {
      log "Required system utility is unavailable: $utility"
      exit 1
    }
  done
  /usr/sbin/apache2ctl configtest >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log "Apache configuration validation failed before installation. See $LOG_FILE."
    exit 1
  }
  log 'Apache configuration accepted. DocumentRoot warnings from other virtual hosts do not fail this check; the PBX and SLS routes are verified separately.'
  systemctl is-active --quiet apache2 || {
    log "Apache is not active."
    exit 1
  }
  systemctl is-active --quiet cron || {
    if [ "${1:-}" = check ]; then
      log 'The cron scheduler is inactive; the approved dependency phase will repair/start it before activation.'
    else
      log 'The cron scheduler is still inactive after dependency repair. Start cron and retry.'
      exit 1
    fi
  }
  apache_modules="$(/usr/sbin/apache2ctl -M 2>/dev/null)"
  for apache_module in rewrite_module setenvif_module; do
    printf '%s\n' "$apache_modules" | grep -Fq "$apache_module" || {
      log "Required Apache module is not loaded: $apache_module"
      exit 1
    }
  done
  asterisk -rx "core show version" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log "The Asterisk control socket is unavailable. Start Asterisk before installing."
    exit 1
  }
  verify_supported_asterisk_directories || {
    log "Asterisk uses paths this release does not support; no module files were activated."
    exit 1
  }
  validate_piper_wrapper_ownership /usr/local/bin/piper || exit 1
  preflight_piper_platform || exit 1
  if [ "${1:-}" = check ]; then return 0; fi
  local module_name
  for module_name in \
    res_pjsip.so res_pjsip_session.so chan_pjsip.so res_pjsip_notify.so \
    pbx_spool.so format_wav.so; do
    ensure_asterisk_module_loaded "$module_name" || exit 1
  done
  asterisk_channel_type_available Local || {
    log "Required Asterisk Local channel driver is unavailable."
    exit 1
  }
  ensure_required_asterisk_capabilities || {
    log "Required Asterisk paging capabilities are unavailable before module activation."
    exit 1
  }
  verify_asterisk_module_startup_persistence || exit 1
  secure_asterisk_spool || exit 1
}

preflight_piper_platform() {
  [ -x /usr/bin/unshare ] && [ -x /usr/bin/mount ] || {
    log 'Piper environment repair requires util-linux unshare and mount. Install that prerequisite before retrying; no SLS runtime was changed.'
    return 1
  }
  /usr/bin/timeout 5 /usr/bin/unshare --mount --propagation private /usr/bin/mount --bind / / >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log 'This host does not permit the private mount namespace required for safe Piper environment replacement. Grant this capability to the test/container host or use a supported FreePBX VM; existing runtime and services were preserved.'
    return 1
  }
  /usr/bin/python3 -I -c 'import ctypes; assert hasattr(ctypes.CDLL(None), "renameat2")' || {
    log 'Atomic Piper replacement requires renameat2 support in the system libc. Existing runtime and services were preserved.'
    return 1
  }
}

secure_asterisk_spool() {
  /usr/bin/python3 -I - <<'PYSPOOL'
import os, pwd, stat
account = pwd.getpwnam('asterisk')
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', flags)
handles = []
try:
    for part in ('var', 'spool', 'asterisk'):
        child = os.open(part, flags, dir_fd=parent)
        os.close(parent); parent = child
        info = os.fstat(parent)
        allowed = (0, account.pw_uid) if part == 'asterisk' else (0,)
        if info.st_uid not in allowed or part != 'asterisk' and info.st_mode & 0o022:
            raise RuntimeError('Unsafe Asterisk spool parent; no spool permissions were changed')
    for name, mode in (('tmp', 0o775), ('outgoing', 0o775), ('outgoing_done', 0o750)):
        try: child = os.open(name, flags, dir_fd=parent)
        except FileNotFoundError:
            os.mkdir(name, 0o750, dir_fd=parent)
            child = os.open(name, flags, dir_fd=parent)
        handles.append((name, child, mode))
        info = os.fstat(child)
        current = os.stat(name, dir_fd=parent, follow_symlinks=False)
        if info.st_uid not in (0, account.pw_uid) or (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino):
            raise RuntimeError('Unsafe Asterisk spool directory; no spool permissions were changed')
    # Validate all existing destinations before applying any permission repair.
    for name, child, mode in handles:
        current = os.stat(name, dir_fd=parent, follow_symlinks=False)
        info = os.fstat(child)
        if (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino):
            raise RuntimeError('Asterisk spool directory changed during preparation')
        os.fchown(child, account.pw_uid, account.pw_gid)
        os.fchmod(child, mode)
finally:
    for _, child, _ in handles: os.close(child)
    os.close(parent)
PYSPOOL
}

validate_piper_wrapper_ownership() {
  local wrapper="${1:-/usr/local/bin/piper}"
  local target
  if [ ! -e "$wrapper" ] && [ ! -L "$wrapper" ]; then
    return 0
  fi
  if [ -L "$wrapper" ]; then
    target="$(readlink "$wrapper" 2>/dev/null || true)"
    case "$target" in
      /usr/local/bin/sls_mass_notify/piper/venv/bin/piper|/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper)
        return 0
        ;;
    esac
  elif [ -f "$wrapper" ] && grep -Eq 'sls_mass_notify/piper|SLS_Mass_Notifications_Plugin/piper' "$wrapper" 2>/dev/null; then
    return 0
  fi
  log "$wrapper already belongs to another application. SLS Mass Notify did not overwrite it."
  log "Move or rename that wrapper explicitly, then rerun the installer if SLS should own the compatibility path."
  return 1
}

install_bootstrap_dependencies() {
  local -a missing_packages=()

  [ -x /usr/bin/php ] || missing_packages+=(php-cli)
  if [ -x /usr/bin/php ]; then
    /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' >/dev/null 2>&1 \
      || missing_packages+=(php-common)
  else
    missing_packages+=(php-common)
  fi
  { [ -x /usr/bin/flock ] && [ -x /usr/sbin/runuser ]; } || missing_packages+=(util-linux)
  { [ -x /usr/bin/timeout ] && [ -x /usr/bin/readlink ]; } || missing_packages+=(coreutils)
  [ -x /usr/bin/timedatectl ] || missing_packages+=(systemd)
  [ -r /usr/share/zoneinfo/UTC ] || missing_packages+=(tzdata)
  [ -x /usr/bin/openssl ] || missing_packages+=(openssl)
  [ -x /usr/bin/gpg ] || missing_packages+=(gnupg)
  { [ -x /usr/bin/curl ] || [ -x /usr/bin/wget ]; } || missing_packages+=(curl)

  if [ "${#missing_packages[@]}" -gt 0 ]; then
    command -v apt-get >/dev/null 2>&1 || {
      log "Missing installation bootstrap prerequisites and no supported Debian package manager is available: ${missing_packages[*]}"
      exit 1
    }
    log "Installing missing installation bootstrap prerequisites: ${missing_packages[*]}"
    refresh_apt_metadata || {
      log "Unable to refresh Debian package metadata for bootstrap prerequisites. See $LOG_FILE."
      exit 1
    }
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends --no-remove "${missing_packages[@]}"
  fi

  for bootstrap_utility in /usr/bin/php /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/bin/timedatectl /usr/sbin/runuser; do
    [ -x "$bootstrap_utility" ] || {
      log "A required installation bootstrap prerequisite is unavailable after dependency installation: $bootstrap_utility"
      exit 1
    }
  done
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials. Install php-common, then rerun the installer."
    exit 1
  }
  [ -r /usr/share/zoneinfo/UTC ] || {
    log "The IANA timezone database is required. Install tzdata, then rerun the installer."
    exit 1
  }
}

install_dependencies() {
  local package
  local -a missing_packages=()
  add_missing_package() {
    local candidate="$1"
    local existing
    for existing in "${missing_packages[@]:-}"; do
      [ "$existing" = "$candidate" ] && return 0
    done
    missing_packages+=("$candidate")
  }

  if command -v apt-get >/dev/null; then
    [ -x /usr/bin/curl ] || add_missing_package curl
    [ -x /usr/bin/wget ] || add_missing_package wget
    [ -r /etc/ssl/certs/ca-certificates.crt ] || add_missing_package ca-certificates
    [ -x /usr/bin/gpg ] || add_missing_package gnupg
    [ -x /usr/bin/openssl ] || add_missing_package openssl
    [ -x /usr/sbin/logrotate ] || add_missing_package logrotate
    [ -x /usr/bin/python3 ] || add_missing_package python3
    if [ -x /usr/bin/python3 ]; then
      /usr/bin/python3 -I -c 'import venv' >/dev/null 2>&1 || add_missing_package python3-venv
      /usr/bin/python3 -I -m pip --version >/dev/null 2>&1 || add_missing_package python3-pip
    else
      add_missing_package python3-venv
      add_missing_package python3-pip
    fi
    { [ -x /usr/bin/sox ] && [ -x /usr/bin/soxi ]; } || add_missing_package sox
    { [ -x /usr/bin/convert ] && [ -x /usr/bin/identify ]; } || add_missing_package imagemagick
    [ -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf ] || add_missing_package fonts-dejavu-core
    [ -x /usr/bin/tar ] || add_missing_package tar
    [ -x /usr/bin/php ] || add_missing_package php-cli
    if [ -x /usr/bin/php ]; then
      local sls_php_runtime_version
      sls_php_runtime_version="$(/usr/bin/php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
      [[ "$sls_php_runtime_version" =~ ^[0-9]+\.[0-9]+$ ]] || { log "Cannot identify the active PHP runtime for extension installation."; exit 1; }
      /usr/bin/php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-sqlite3"
      /usr/bin/php -r 'exit(function_exists("curl_init") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-curl"
      /usr/bin/php -r 'exit(class_exists("DOMDocument") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-xml"
      /usr/bin/php -r 'exit(extension_loaded("mbstring") && function_exists("mb_strlen") && function_exists("mb_substr") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-mbstring"
      /usr/bin/php -r 'exit(function_exists("posix_getpwnam") && function_exists("openssl_encrypt") && function_exists("openssl_decrypt") && function_exists("sodium_crypto_pwhash") && function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-common"
    else
      add_missing_package php-mbstring
      add_missing_package php-sqlite3
      add_missing_package php-curl
      add_missing_package php-xml
    fi
    local served_profile served_version served_sapi served_package
    while IFS= read -r served_profile; do
      [ -n "$served_profile" ] || continue
      served_version="${served_profile%:*}"; served_sapi="${served_profile#*:}"
      for served_package in cli common sqlite3 curl xml mbstring; do
        dpkg-query -W -f='${Status}' "php${served_version}-${served_package}" 2>/dev/null | grep -Fqx 'install ok installed' \
          || add_missing_package "php${served_version}-${served_package}"
      done
    done <<<"$SERVED_PHP_PROFILES"
    /usr/bin/python3 -I -c 'from cryptography.hazmat.primitives.ciphers.aead import AESGCM' >/dev/null 2>&1 || add_missing_package python3-cryptography
    [ -x /usr/bin/node ] || add_missing_package nodejs
    { [ -x /usr/bin/crontab ] && [ -x /usr/sbin/cron ]; } || add_missing_package cron
    { [ -x /usr/bin/flock ] && [ -x /usr/sbin/runuser ]; } || add_missing_package util-linux
    { [ -x /usr/bin/timeout ] && [ -x /usr/bin/readlink ]; } || add_missing_package coreutils

    if [ "${#missing_packages[@]}" -gt 0 ]; then
      log "Installing missing runtime prerequisites: ${missing_packages[*]}"
      refresh_apt_metadata || {
        log "Unable to refresh Debian package metadata for missing prerequisites. See $LOG_FILE."
        exit 1
      }
      DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends --no-remove "${missing_packages[@]}"
    else
      log "Runtime package prerequisites are already available; skipping apt metadata refresh."
    fi
  else
    command -v curl >/dev/null || command -v wget >/dev/null || {
      log "curl or wget is required to download the module."
      exit 1
    }
    command -v python3 >/dev/null || {
      log "python3 is required."
      exit 1
    }
    command -v sox >/dev/null || {
      log "sox is required."
      exit 1
    }
    { command -v convert >/dev/null && command -v identify >/dev/null; } || {
      log "ImageMagick is required for phone alert images."
      exit 1
    }
  fi
  [ -x /usr/bin/php ] || {
    log "The scheduling worker requires the canonical /usr/bin/php CLI executable. Install php-cli, then rerun the installer."
    exit 1
  }
  /usr/bin/php -r 'exit(extension_loaded("pdo_sqlite") && function_exists("curl_init") && class_exists("DOMDocument") ? 0 : 1);' || {
    log "The active PHP CLI still lacks SQLite, cURL or XML after dependency installation. Enable the matching PHP-version extensions and verify the PBX web PHP runtime uses them too. No module activation was attempted."
    exit 1
  }
  /usr/bin/php -r 'exit(extension_loaded("mbstring") && function_exists("mb_strlen") && function_exists("mb_substr") ? 0 : 1);' || {
    log "The PHP mbstring extension is required for safe alert text validation. Install php-mbstring, then rerun the installer."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("posix_getpwnam") ? 0 : 1);' || {
    log "PHP POSIX support is required to resolve the configured FreePBX service account safely. Install php-common, then rerun the installer."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("sodium_crypto_pwhash") && function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") ? 0 : 1);' || {
    log "PHP Sodium is unavailable for authenticated encrypted configuration backups. Enable sodium for the installed PHP CLI and PBX web PHP versions, then rerun the installer. The protected configuration was not rewritten."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials. Install php-common, then rerun the installer."
    exit 1
  }
  [ -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf ] || {
    log "The DejaVu Sans Bold font required for phone alert images is unavailable after dependency installation."
    exit 1
  }
  for package in /usr/bin/curl /usr/bin/wget /usr/bin/gpg /usr/bin/python3 /usr/bin/sox /usr/bin/soxi /usr/bin/convert /usr/bin/identify /usr/bin/tar /usr/bin/crontab /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/sbin/runuser; do
    [ -x "$package" ] || {
      log "A required runtime prerequisite is still unavailable after dependency installation: $package"
      exit 1
    }
  done
  if [ "$STATIC_MUTATION_STARTED" -eq 1 ] && [ "$FREEPBX_CONFIRMED" -eq 1 ]; then
  systemctl is-active --quiet cron || {
    log 'Starting the stock cron scheduler after authenticated upgrade approval.'
    systemctl start cron || { log 'The stock cron scheduler could not start; no module activation was attempted.'; return 1; }
  }
  fi
}

preflight_python() {
  command -v python3 >/dev/null || {
    log "python3 is required."
    exit 1
  }
  python3 --version >/dev/null 2>&1 || {
    log "python3 is installed but not executable or broken."
    exit 1
  }
  python_path="$(command -v python3)"
  [ -x "$python_path" ] || {
    log "$python_path exists but is not executable."
    exit 1
  }
  /usr/bin/python3 -I - <<'PYABI' || return 1
import platform, sys
if sys.implementation.name != 'cpython' or sys.version_info[:2] != (3, 11) or platform.machine() not in ('x86_64', 'amd64'):
    raise SystemExit('This release qualifies Debian 12 / CPython 3.11 / amd64 binary dependencies. This interpreter or architecture needs a separately tested, hash-locked wheel profile; no protected runtime was changed.')
if platform.libc_ver()[0] != 'glibc':
    raise SystemExit('The qualified Piper wheel profile requires glibc; this libc needs a separately tested profile.')
print('Qualified Python ABI and architecture verified before protected runtime preparation.')
PYABI
}

bootstrap_missing_dashboard() {
  [ ! -e /var/www/html/admin/modules/dashboard ] && [ ! -L /var/www/html/admin/modules/dashboard ] || return 0
  local version="${SLS_MASS_NOTIFY_BOOTSTRAP_DASHBOARD_VERSION:-}" evidence
  if [ -z "$version" ]; then
    version="$(php -r '$bootstrap_settings=["freepbx_auth"=>false,"skip_astman"=>true]; require "/etc/freepbx.conf"; $modules=\module_functions::create()->getonlinexml("dashboard"); echo (string)($modules["dashboard"]["version"] ?? "");')" || return 1
  fi
  [[ "$version" =~ ^17\.[0-9]+(\.[0-9]+){1,3}$ ]] || {
    log 'Dashboard is absent and its stock version could not be discovered. Supply SLS_MASS_NOTIFY_BOOTSTRAP_DASHBOARD_VERSION=17.x.x and its independently signed upstream package; no SLS maintenance was changed.'
    return 1
  }
  evidence="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-stock-bootstrap.XXXXXX")" || return 1
  chmod 0700 "$evidence" || return 1
  run_logged /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" --workspace "$evidence" --bootstrap-dashboard-version "$version" || return 1
  log "Installing independently authenticated stock Dashboard $version. Stock dependency evidence: $evidence"
  fwconsole ma --no-interaction --skipchown install dashboard || {
    log 'The authenticated stock Dashboard was staged, but FreePBX could not register it. Preserve the stock bootstrap evidence; repair Dashboard through Module Admin before retrying. Existing SLS runtime and maintenance remain unchanged.'
    return 1
  }
  fwconsole ma --no-interaction enable dashboard || return 1
}

detect_served_php_profiles() {
  SERVED_PHP_PROFILES="$(/usr/bin/python3 -I - <<'PYPHPHANDLER'
from pathlib import Path
import configparser, os, pwd, re, stat, subprocess

def protected_config(path, freepbx_managed=False):
    # Validate enabled links and their resolved sources. Only FreePBX-managed
    # Apache evidence admits asterisk ownership; FPM pool evidence stays root-owned.
    path=Path(path);owners={0}
    if freepbx_managed:
        try: owners.add(pwd.getpwnam('asterisk').pw_uid)
        except KeyError: raise SystemExit('The asterisk account is required to inspect FreePBX-managed Apache configuration.')
    for item in (path,*path.parents):
        info=item.lstat()
        if info.st_uid not in owners: raise SystemExit('PHP handler evidence has an unexpected owner: '+str(path))
        if stat.S_ISLNK(info.st_mode): continue
        if info.st_mode&0o022 and not (item==Path('/tmp') and info.st_mode&stat.S_ISVTX):
            raise SystemExit('PHP handler evidence has a writable parent or source: '+str(path))
    resolved=path.resolve(strict=True)
    for item in (resolved,*resolved.parents):
        info=item.lstat()
        if info.st_uid not in owners or info.st_mode&0o022 and not (item==Path('/tmp') and info.st_mode&stat.S_ISVTX):
            raise SystemExit('PHP handler evidence is not protected: '+str(path))
    info=resolved.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_size>1048576:
        raise SystemExit('PHP handler evidence must be a bounded regular file: '+str(path))
    fd=os.open(resolved,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC)
    with os.fdopen(fd,'rb') as handle:
        before=os.fstat(handle.fileno());body=handle.read(1048577);after=os.fstat(handle.fileno())
    identity=lambda value:(value.st_dev,value.st_ino,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
    if not stat.S_ISREG(before.st_mode) or before.st_nlink!=1 or before.st_uid not in owners or before.st_mode&0o022 or len(body)>1048576 or len(body)!=before.st_size or identity(before)!=identity(after):
        raise SystemExit('PHP handler evidence changed or exceeded its inspection bound: '+str(path))
    return body.decode('utf-8','replace')

def local_socket(path):
    return '/run/php/'+path[len('/var/run/php/'):] if path.startswith('/var/run/php/') else path

result = subprocess.run(['/usr/sbin/apache2ctl','-t','-D','DUMP_INCLUDES'], capture_output=True, text=True, timeout=20)
if result.returncode: raise SystemExit('Apache includes cannot be inspected before dependency repair.')
files = set(re.findall(r'(/[^\s]+)', result.stdout + '\n' + result.stderr))
files.update(str(path) for path in Path('/etc/apache2/mods-enabled').glob('php*.load'))
if len(files)>512: raise SystemExit('Apache include inspection exceeded 512 files.')
profiles=set(); unknown=[]; apache_users=[]; served_sockets=set()
for name in files:
    path=Path(name)
    if not path.is_file(): continue
    text=protected_config(path,freepbx_managed=True)
    active='\n'.join(line for line in text.splitlines() if not line.lstrip().startswith('#'))
    for version in re.findall(r'libphp(\d+\.\d+)\.so', active): profiles.add((version,'apache2'))
    apache_users.extend(re.findall(r'^\s*User\s+([^\s#]+)',active,re.M))
    for line in active.splitlines():
        if not re.search(r'^\s*(?:SetHandler|ProxyPass(?:Match)?|BalancerMember)\b.*(?:fcgi|proxy:balancer)',line,re.I): continue
        local=re.findall(r'(?:proxy:)?unix:(/[^\s|"\']+)\|fcgi://(?:localhost|127\.0\.0\.1|\[::1\])(?=[/\s"\']|$)',line,re.I)
        if len(local)!=1 or len(re.findall(r'fcgi://',line,re.I))!=1 or not re.fullmatch(r'/(?:run|var/run)/php/[A-Za-z0-9_.-]{1,128}\.sock',local[0]):
            unknown.append(name);continue
        served_sockets.add(local_socket(local[0]))
if unknown: raise SystemExit('Apache has an unidentified or remote FastCGI handler in '+', '.join(sorted(set(unknown)))+'. Use a protected local PHP-FPM pool/socket or qualify an explicit handler adapter; CLI PHP cannot establish its served runtime.')
if len(served_sockets)>32: raise SystemExit('More than 32 served PHP-FPM sockets require explicit administrator review.')
try: expected_uid=pwd.getpwnam('asterisk').pw_uid
except KeyError: raise SystemExit('The nonroot asterisk account is required before PHP-handler qualification.')
if expected_uid<=0: raise SystemExit('The asterisk account must not be root.')
def uid(name):
    try: return int(name) if name.isdecimal() else pwd.getpwnam(name).pw_uid
    except (KeyError,ValueError): return -1
if any(sapi=='apache2' for unused,sapi in profiles):
    if not apache_users: raise SystemExit('Apache mod_php has no inspectable User directive. Qualify its runtime identity before installation.')
    envvars=Path('/etc/apache2/envvars')
    environment=protected_config(envvars,freepbx_managed=True) if envvars.is_file() else ''
    match=re.search(r'^\s*(?:export\s+)?APACHE_RUN_USER\s*=\s*([A-Za-z0-9_\-]+|\'[A-Za-z0-9_\-]+\'|"[A-Za-z0-9_\-]+")\s*(?:#.*)?$',environment,re.M)
    for name in apache_users:
        if name=='${APACHE_RUN_USER}': name=match.group(1).strip('\'"') if match else ''
        if uid(name)!=expected_uid: raise SystemExit('Apache mod_php must run as the asterisk service account. Review the Apache User directive/envvars; SLS will not change the global web-server user.')
required_functions={'exec','proc_open','posix_geteuid','posix_getpwnam','openssl_encrypt','openssl_decrypt','sodium_crypto_pwhash','sodium_crypto_pwhash_str','sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'}
matched={socket:[] for socket in served_sockets}
if served_sockets:
    directories=sorted(Path('/etc/php').glob('*/fpm'))
    if len(directories)>20: raise SystemExit('PHP-FPM version inspection exceeded 20 configurations.')
    for directory in directories:
        version=directory.parent.name
        if not re.fullmatch(r'\d+\.\d+',version): continue
        global_config=directory/'php-fpm.conf'
        if not global_config.is_file(): continue
        includes=re.findall(r'^\s*include\s*=\s*([^;\n]+)',protected_config(global_config),re.M)
        expected_include=str(directory/'pool.d/*.conf')
        standard_include=bool(includes) and all(value.strip()==expected_include for value in includes)
        pools=list((directory/'pool.d').glob('*.conf'))
        if len(pools)>100: raise SystemExit('PHP-FPM pool inspection exceeded 100 configuration files.')
        for path in pools:
            parser=configparser.ConfigParser(interpolation=None,strict=True,inline_comment_prefixes=(';',),delimiters=('=',))
            try: parser.read_string(protected_config(path))
            except configparser.Error: raise SystemExit('PHP-FPM pool configuration could not be parsed safely: '+str(path))
            for section in parser.sections():
                pool=parser[section];listen=local_socket(pool.get('listen','').strip())
                if listen not in matched: continue
                if not standard_include: raise SystemExit('PHP-FPM '+version+' uses a custom pool include layout; qualify its active pool and user before installation.')
                matched[listen].append((version,section,dict(pool)))
    for listen,rows in matched.items():
        if len(rows)!=1: raise SystemExit('Each served PHP-FPM socket must match exactly one configured local pool; qualify this layout before activation.')
        version,section,pool=rows[0]
        # A version-looking socket name is only a hint. Its configured pool and
        # fixed, root-protected FPM binary must independently establish the ABI.
        hint=re.search(r'/php(\d+\.\d+)-fpm(?:-[A-Za-z0-9_.-]+)?\.sock$',listen)
        if hint and hint[1]!=version: raise SystemExit('The served PHP-FPM socket name and configured pool ABI disagree: '+listen)
        binary=Path('/usr/sbin')/('php-fpm'+version)
        for item in (binary,*binary.parents):
            info=item.lstat()
            expected=stat.S_ISREG if item==binary else stat.S_ISDIR
            if not expected(info.st_mode) or info.st_uid!=0 or info.st_mode&0o022: raise SystemExit('The versioned PHP-FPM binary is not root-protected: '+str(binary))
        result=subprocess.run([str(binary),'-n','-v'],capture_output=True,text=True,timeout=5)
        abi=re.match(r'^PHP (\d+\.\d+)\.\d+[^\n]*\(fpm-fcgi\)',result.stdout)
        if result.returncode or not abi or abi[1]!=version: raise SystemExit('The configured PHP-FPM pool and installed binary ABI disagree: '+listen)
        if uid(pool.get('user','').strip())!=expected_uid: raise SystemExit('The PHP-FPM pool serving '+listen+' must run as asterisk. Configure this pool explicitly; SLS will not grant other web users access to PBX secrets.')
        disabled=set(re.split(r'[,\s]+',pool.get('php_admin_value[disable_functions]','')+','+pool.get('php_value[disable_functions]','')))
        blocked=sorted(disabled & required_functions)
        if blocked: raise SystemExit('The PHP-FPM pool serving '+listen+' disables required functions: '+', '.join(blocked)+'. Review this pool before activation.')
        profiles.add((version,'fpm'))
if not profiles: raise SystemExit('No qualified Apache PHP module or local PHP-FPM pool was detected. Qualify this handler before installation.')
if len(profiles)>4: raise SystemExit('More than four active PHP handler profiles require explicit administrator review.')
for version,sapi in sorted(profiles):
    if tuple(map(int,version.split('.')))<(8,2): raise SystemExit('Apache PHP '+version+' is unsupported; use a qualified PHP 8.2 or newer handler before installation.')
    print(version+':'+sapi)
PYPHPHANDLER
)" || return 1
  log "Detected served PHP profiles: ${SERVED_PHP_PROFILES//$'\n'/, }"
}

verify_served_php_configuration() {
  local profile version sapi
  while IFS= read -r profile; do
    [ -n "$profile" ] || continue
    version="${profile%:*}"; sapi="${profile#*:}"
    [ -x "/usr/bin/php$version" ] && [ -r "/etc/php/$version/$sapi/php.ini" ] || { log "The served PHP $version/$sapi configuration is unavailable; repair that handler before activation."; return 1; }
    sls_as_asterisk /usr/bin/env "PHP_INI_SCAN_DIR=/etc/php/$version/$sapi/conf.d" "/usr/bin/php$version" -c "/etc/php/$version/$sapi/php.ini" -r '
$missing=[]; foreach (["pdo_sqlite","curl","dom","mbstring","posix","openssl","sodium"] as $extension) { if (!extension_loaded($extension)) $missing[]=$extension; }
if (PHP_VERSION_ID < 80200) $missing[]="php_8_2_or_newer";
foreach (["exec","proc_open","posix_geteuid","posix_getpwnam","openssl_encrypt","openssl_decrypt","sodium_crypto_pwhash","sodium_crypto_aead_xchacha20poly1305_ietf_encrypt"] as $function) { if (!function_exists($function)) $missing[]=$function."_enabled"; }
if ($missing) { fwrite(STDERR,"Served PHP configuration missing: ".implode(", ",$missing).PHP_EOL); exit(1); }
' || { log "Enable required extensions for PHP $version/$sapi; no module activation has started."; return 1; }
  done <<<"$SERVED_PHP_PROFILES"
}

fetch_release_asset() {
  SLS_ASSET_URL="$1" SLS_ASSET_OUTPUT="$2" SLS_ASSET_LIMIT="$3" SLS_ASSET_TOKEN="$TOKEN" /usr/bin/timeout --signal=TERM --kill-after=5 905 /usr/bin/python3 -I - <<'PY'
import os
import re
import socket
import time
import urllib.error
import urllib.parse
import urllib.request

url = os.environ['SLS_ASSET_URL']
pattern = (r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/'
           r'slsmassnotifyserver-([0-9]+\.[0-9]+\.[0-9]+(?:-beta)?)/'
           r'(?:slsmassnotifyserver-\1\.tgz|release-manifest\.json|release-manifest\.sig)')
if not re.fullmatch(pattern, url):
    raise SystemExit('Use a signed HTTPS GitHub release URL, or an offline TGZ with an explicitly trusted SHA-256.')
limit = int(os.environ['SLS_ASSET_LIMIT'])
if not 1 <= limit <= 52 * 1024 * 1024:
    raise SystemExit('Invalid release download limit.')
token = os.environ.get('SLS_ASSET_TOKEN', '')
if any(ord(character) < 32 or ord(character) == 127 for character in token):
    raise SystemExit('Invalid GitHub token format.')

class ReleaseRedirects(urllib.request.HTTPRedirectHandler):
    max_redirections = 5
    def redirect_request(self, request, response, code, message, headers, new_url):
        target = urllib.parse.urlsplit(new_url)
        if (target.scheme != 'https' or target.username or target.password
                or target.port not in (None, 443) or target.fragment
                or target.hostname not in {'github.com', 'objects.githubusercontent.com',
                                           'release-assets.githubusercontent.com'}):
            raise ValueError('Release download redirected outside approved HTTPS asset hosts.')
        redirected = super().redirect_request(request, response, code, message, headers, new_url)
        # Authentication applies only to the original GitHub request. Signed
        # asset redirects do not need it, including a later redirect back.
        if redirected is not None:
            redirected.remove_header('Authorization')
        return redirected

request = urllib.request.Request(url, headers={
    'User-Agent': 'SLS-Mass-Notify-Installer', 'Accept': 'application/octet-stream',
    'Accept-Encoding': 'identity',
})
if token:
    request.add_unredirected_header('Authorization', 'Bearer ' + token)
opener = urllib.request.build_opener(ReleaseRedirects())
path = os.environ['SLS_ASSET_OUTPUT']
deadline = time.monotonic() + 900
try:
    # The caller owns the private parent directory; never truncate or follow
    # a pre-existing pathname, even inside that directory.
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as output:
        for attempt in range(5):
            try:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError('Release download deadline exceeded.')
                with opener.open(request, timeout=min(20, remaining)) as response:
                    length = response.headers.get('Content-Length')
                    if length is not None and (not length.isdigit() or int(length) > limit):
                        raise ValueError('Release asset exceeds its download limit.')
                    total = 0
                    while True:
                        if time.monotonic() >= deadline:
                            raise TimeoutError('Release download deadline exceeded.')
                        chunk = response.read(min(65536, limit - total + 1))
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > limit:
                            raise ValueError('Release asset exceeds its download limit.')
                        output.write(chunk)
                    if total == 0 or (length is not None and total != int(length)):
                        raise ValueError('Release asset was empty or incomplete.')
                output.flush()
                os.fsync(output.fileno())
                break
            except (urllib.error.URLError, socket.timeout, TimeoutError):
                if attempt == 4 or time.monotonic() >= deadline:
                    raise
                output.seek(0)
                output.truncate()
                time.sleep(min(2 ** attempt, max(0, deadline - time.monotonic())))
except Exception:
    # Do not include redirected URLs, signed query parameters, or headers in
    # diagnostics. The caller removes the private directory on failure.
    raise SystemExit('Release download failed validation, exceeded its size/time limit, or could not be completed.')
PY
}

copy_private_release_asset() {
  /usr/bin/python3 -I - "$1" "$2" "$3" <<'PYASSET'
import os, stat, sys
source, target, bound = sys.argv[1], sys.argv[2], int(sys.argv[3])
fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
try:
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= bound:
        raise RuntimeError('release asset must be a bounded, single-link regular file')
    out = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(out, 'wb') as handle:
        total = 0
        while True:
            block = os.read(fd, 65536)
            if not block: break
            total += len(block)
            if total > bound: raise RuntimeError('release asset exceeds size limit')
            handle.write(block)
        handle.flush()
        os.fsync(handle.fileno())
    after = os.fstat(fd)
    if total != info.st_size or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
        raise RuntimeError('release asset changed during private copy')
finally:
    os.close(fd)
PYASSET
}

download_tgz() {
  local original_tgz="$TGZ"
  DOWNLOAD_DIR="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-download.XXXXXX")" || return 1
  if [ -n "$URL" ]; then
    TGZ="$DOWNLOAD_DIR/package.tgz"
    fetch_release_asset "$URL" "$TGZ" 54525952 || return 1
  else
    TGZ="$DOWNLOAD_DIR/package.tgz"
    copy_private_release_asset "$original_tgz" "$TGZ" 54525952 || return 1
  fi

  [ -f "$TGZ" ] && [ ! -L "$TGZ" ] && [ -s "$TGZ" ] || {
    log "$TGZ is missing. Set SLS_MASS_NOTIFY_TGZ_URL or upload the TGZ to this path first."
    exit 1
  }
  chmod 0600 "$TGZ"
}

verify_tgz() {
  actual_sha="$(sha256sum "$TGZ" | awk '{print $1}')"
  if [ -n "$SHA256" ]; then
    echo "$SHA256  $TGZ" | sha256sum -c -
  elif [ "$(basename "$TGZ")" = "slsmassnotifyserver-0.1.6-beta.tgz" ] && [ "$actual_sha" != "$EXPECTED_TGZ_SHA256" ]; then
		log "$TGZ does not match the current slsmassnotifyserver-0.1.6-beta package."
    log "Expected SHA256: $EXPECTED_TGZ_SHA256"
    log "Actual SHA256:   $actual_sha"
    log "Remove the stale local TGZ or install with SLS_MASS_NOTIFY_TGZ_URL so the current release is downloaded."
    exit 1
  else
    printf '%s  %s\n' "$actual_sha" "$TGZ"
  fi
  if [ -n "$URL" ]; then
    verify_publisher_release
  else
    [ -n "$SHA256" ] || { log 'A local/offline TGZ requires an explicit trusted SLS_MASS_NOTIFY_SHA256.'; return 1; }
    verify_publisher_release
  fi
  TGZ_PATH="$TGZ" MODULE_NAME="$MODULE" python3 -I - <<'PY'
import os
import pathlib
import tarfile
import xml.etree.ElementTree as ET

archive = os.environ["TGZ_PATH"]
module = os.environ["MODULE_NAME"]
total = 0
seen_module_xml = False
with tarfile.open(archive, "r:gz") as handle:
    members = handle.getmembers()
    if not members or len(members) > 2000:
        raise SystemExit("TGZ has an invalid file count")
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or ".." in path.parts or not path.parts or path.parts[0] != module:
            raise SystemExit(f"Unsafe or unexpected TGZ path: {member.name}")
        if len(member.name) > 240 or member.mode & 0o6000:
            raise SystemExit(f"Unsafe TGZ metadata: {member.name}")
        if member.issym() or member.islnk() or member.isdev() or member.isfifo():
            raise SystemExit(f"Unsupported TGZ member type: {member.name}")
        if member.isfile():
            total += member.size
            if member.name == f"{module}/module.xml":
                seen_module_xml = True
    if total > 50 * 1024 * 1024:
        raise SystemExit("TGZ expands beyond the 50 MB module limit")
    if not seen_module_xml:
        raise SystemExit("TGZ does not contain the required module.xml")
    module_xml = handle.extractfile(f"{module}/module.xml")
    if module_xml is None:
        raise SystemExit("Unable to read module.xml")
    root = ET.fromstring(module_xml.read())
    if (root.findtext("rawname") or "").strip() != module:
        raise SystemExit("module.xml rawname does not match the requested module")
    if (root.findtext("version") or "").strip() != "0.1.6-beta":
        raise SystemExit("module.xml does not contain the expected 0.1.6-beta version")
PY
}

verify_publisher_release() {
  [ -n "$DOWNLOAD_DIR" ] && [ -d "$DOWNLOAD_DIR" ] || return 1
  if [ -n "$URL" ]; then
    fetch_release_asset "${URL%/*}/release-manifest.json" "$DOWNLOAD_DIR/release-manifest.json" 16384 || return 1
    fetch_release_asset "${URL%/*}/release-manifest.sig" "$DOWNLOAD_DIR/release-manifest.sig" 64 || return 1
  else
    copy_private_release_asset "${SLS_MASS_NOTIFY_MANIFEST:-${OFFLINE_ASSET_DIR}/release-manifest.json}" "$DOWNLOAD_DIR/release-manifest.json" 16384 || return 1
    copy_private_release_asset "${SLS_MASS_NOTIFY_MANIFEST_SIGNATURE:-${OFFLINE_ASSET_DIR}/release-manifest.sig}" "$DOWNLOAD_DIR/release-manifest.sig" 64 || return 1
  fi
  SLS_RELEASE_DIRECTORY="$DOWNLOAD_DIR" SLS_RELEASE_URL="$URL" SLS_RELEASE_TGZ="$TGZ" SLS_RELEASE_INSTALLER="$INSTALLER_SOURCE_PATH" /usr/bin/python3 -I - <<'PY'
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
import types

url = os.environ['SLS_RELEASE_URL']
match = re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/slsmassnotifyserver-([0-9]+\.[0-9]+\.[0-9]+(?:-beta)?)/(slsmassnotifyserver-\1\.tgz)', url)
if url and not match:
    raise SystemExit('Use a signed GitHub release URL or a signed offline package.')
version, package = match.groups() if match else ('0.1.6-beta', 'slsmassnotifyserver-0.1.6-beta.tgz')
public_key = b'-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=\n-----END PUBLIC KEY-----\n'
try:
    with tempfile.TemporaryDirectory(prefix='sls-release-verification-') as directory:
        root = Path(directory)
        for name, limit in [('release-manifest.json', 16384), ('release-manifest.sig', 64)]:
            body = (Path(os.environ['SLS_RELEASE_DIRECTORY']) / name).read_bytes()
            if len(body) > limit or (name.endswith('.sig') and len(body) != 64):
                raise ValueError('Invalid manifest/signature size')
            (root / name).write_bytes(body)
        trusted_helper = Path('/usr/local/bin/sls_mass_notify/sls_release_trust.py')
        trust_state = Path('/var/lib/sls-mass-notify-trust')
        if trusted_helper.exists() or trusted_helper.is_symlink():
            for path in (trusted_helper, *trusted_helper.parents):
                metadata = path.lstat()
                expected = stat.S_ISREG if path == trusted_helper else stat.S_ISDIR
                if not expected(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_mode & 0o022:
                    raise ValueError('Installed publisher verifier is not protected by root')
            sys.dont_write_bytecode = True
            fd = os.open(trusted_helper, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            try:
                before = os.fstat(fd)
                if not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_mode & 0o022 or before.st_nlink != 1 or before.st_size > 256 * 1024:
                    raise ValueError('Installed publisher verifier is not a bounded protected source')
                source = bytearray()
                while len(source) <= 256 * 1024:
                    block = os.read(fd, 65536)
                    if not block: break
                    source.extend(block)
                after = os.fstat(fd)
                if len(source) != before.st_size or (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                    raise ValueError('Installed publisher verifier changed during inspection')
            finally: os.close(fd)
            trust = types.ModuleType('installed_publisher_trust'); trust.__file__ = str(trusted_helper)
            exec(compile(source, str(trusted_helper), 'exec'), trust.__dict__)
            manifest = trust.authenticate_manifest((root / 'release-manifest.json').read_bytes(),
                (root / 'release-manifest.sig').read_bytes(), anchor=public_key)
        else:
            if any((trust_state / name).exists() or (trust_state / name).is_symlink()
                   for name in ('publisher-trust.json', 'release-trust.initialized')):
                raise ValueError('Established publisher trust requires its protected verifier; restore it before updating')
            (root / 'publisher.pub').write_bytes(public_key)
            subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-verify', '-rawin', '-pubin', '-inkey', str(root / 'publisher.pub'),
                            '-in', str(root / 'release-manifest.json'), '-sigfile', str(root / 'release-manifest.sig')],
                           check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
            manifest = json.loads((root / 'release-manifest.json').read_bytes())
            signing = manifest.get('signing') if isinstance(manifest, dict) else None
            if signing is not None:
                # The bootstrap accepts only the pinned key, including for the
                # new metadata format. A downloaded policy cannot add authority.
                anchor_der = subprocess.run(['/usr/bin/openssl','pkey','-pubin','-outform','DER'],
                    input=public_key, check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=10).stdout
                now = int(time.time())
                if not isinstance(signing, dict) or set(signing) != {'key_id','issued_at','expires_at'} \
                        or signing['key_id'] != hashlib.sha256(anchor_der).hexdigest() \
                        or type(signing['issued_at']) is not int or type(signing['expires_at']) is not int \
                        or signing['issued_at'] < 0 or signing['issued_at'] > now + 300 \
                        or not signing['issued_at'] < signing['expires_at'] <= signing['issued_at'] + 2 * 366 * 86400 \
                        or now >= signing['expires_at']:
                    raise ValueError('Signed release metadata is expired or invalid')
        if not isinstance(manifest, dict) or manifest.get('schema') != 1 or manifest.get('version') != version \
                or manifest.get('tag') != 'slsmassnotifyserver-' + version or manifest.get('package') != package:
            raise ValueError('Signed release identity mismatch')
        for field, path in [('package_sha256', os.environ['SLS_RELEASE_TGZ']), ('installer_sha256', os.environ['SLS_RELEASE_INSTALLER'])]:
            with open(path, 'rb') as handle:
                actual = hashlib.file_digest(handle, 'sha256').hexdigest()
            if manifest.get(field) != actual:
                raise ValueError('Signed release artifact mismatch: ' + field)
except Exception as error:
    raise SystemExit('Publisher verification failed before activation: ' + str(error))
print('Publisher signature and installer/package hashes verified.')
PY
}

prepare_settings_lock() {
  [ ! -L "$CONFIG_FILE" ] || {
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  }
  [ -e "$CONFIG_FILE" ] || return 0
  SETTINGS_LOCK_PATH="$SETTINGS_LOCK" /usr/bin/python3 -I - <<'PY'
import os
import pwd
import stat

path = os.environ["SETTINGS_LOCK_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path or any(part in (".", "..") for part in parts):
    raise SystemExit("invalid settings-lock path")
if os.geteuid() != 0:
    raise SystemExit("settings-lock preparation requires root")
account = pwd.getpwnam("asterisk")
if account.pw_uid == 0:
    raise SystemExit("Asterisk UID must be nonzero")
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
file_flags = os.O_RDWR | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", directory_flags)
try:
    for index, component in enumerate(parts[:-1]):
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
        info = os.fstat(parent_fd)
        sticky = info.st_uid == 0 and info.st_mode & stat.S_ISVTX and index < len(parts) - 2
        if (info.st_uid not in (0, account.pw_uid) or info.st_mode & 0o002 and not sticky
                or info.st_mode & 0o020 and not sticky and info.st_gid not in (0, account.pw_gid)):
            raise SystemExit("settings-lock parent ownership or permissions are unsafe")
    try:
        file_fd = os.open(parts[-1], file_flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent_fd)
    except FileExistsError:
        file_fd = os.open(parts[-1], file_flags, dir_fd=parent_fd)
    try:
        metadata = os.fstat(file_fd)
        named = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size != 0
                or metadata.st_uid not in (0, account.pw_uid) or metadata.st_gid not in (0, account.pw_gid)
                or stat.S_IMODE(metadata.st_mode) not in (0o600, 0o640, 0o644)
                or (metadata.st_dev, metadata.st_ino) != (named.st_dev, named.st_ino)):
            raise SystemExit("settings lock is not an empty, single-link regular file with recognized private ownership and mode")
        os.fchown(file_fd, account.pw_uid, account.pw_gid)
        os.fchmod(file_fd, 0o640)
        os.fsync(file_fd)
        verified = os.fstat(file_fd)
        named = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
        if (verified.st_uid != account.pw_uid or verified.st_gid != account.pw_gid
                or stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_nlink != 1 or verified.st_size != 0
                or (verified.st_dev, verified.st_ino) != (named.st_dev, named.st_ino)):
            raise SystemExit("settings lock changed during preparation; preserve it for review")
        os.fsync(parent_fd)
    finally:
        os.close(file_fd)
finally:
    os.close(parent_fd)
PY
}

acquire_settings_coordination() {
  [ ! -L "$CONFIG_FILE" ] || {
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  }
  [ -e "$CONFIG_FILE" ] || return 0
  prepare_settings_lock || return 1
  [ ! -L "$SETTINGS_LOCK" ] && [ -f "$SETTINGS_LOCK" ] || {
    log "Refusing to use an unsafe central-configuration lock file."
    exit 1
  }
  # The authenticated keeper opens with no-follow/nonblocking semantics and
  # retains this mutex beside the worker leases. No shell pathname reopen or
  # lock descriptor reaches an unprivileged FreePBX subprocess.
  local status
  if [ -z "${WORKER_LOCK_KEEPALIVE_FD:-}" ] || [ -z "${WORKER_LOCK_RESPONSE_FD:-}" ] \
    || [ -z "${WORKER_LOCK_PID:-}" ] || ! kill -0 "$WORKER_LOCK_PID" 2>/dev/null; then
    log 'The authenticated worker keeper is unavailable; no configuration was replaced.'
    return 1
  fi
  if ! (printf 'settings\n' >&"$WORKER_LOCK_KEEPALIVE_FD") 2>/dev/null \
    || ! IFS= read -r status <&"$WORKER_LOCK_RESPONSE_FD" || [ "$status" != settings-ready ]; then
    log 'The configuration mutex could not be acquired safely. Review its ownership, mode, identity or active editor in the log; no settings were replaced.'
    return 1
  fi
}

safe_config_snapshot() {
  source_path="$1"
  snapshot_path="$2"
  CONFIG_SOURCE_PATH="$source_path" CONFIG_SNAPSHOT_PATH="$snapshot_path" /usr/bin/python3 -I - <<'PY'
import hashlib
import os
import stat

source_path = os.environ["CONFIG_SOURCE_PATH"]
snapshot_path = os.environ["CONFIG_SNAPSHOT_PATH"]
limit = 16 * 1024 * 1024

def open_regular(path, flags):
    parts = [part for part in path.split("/") if part]
    if not path.startswith("/") or not parts or "\x00" in path:
        raise RuntimeError("invalid path")
    directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
    parent_fd = os.open("/", directory_flags)
    try:
        for component in parts[:-1]:
            next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
            os.close(parent_fd)
            parent_fd = next_fd
        file_fd = os.open(parts[-1], flags | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
    finally:
        os.close(parent_fd)
    if not stat.S_ISREG(os.fstat(file_fd).st_mode):
        os.close(file_fd)
        raise RuntimeError("protected config is not a regular file")
    return file_fd

source_fd = open_regular(source_path, os.O_RDONLY)
snapshot_fd = open_regular(snapshot_path, os.O_WRONLY | os.O_TRUNC)
digest = hashlib.sha256()
total = 0
try:
    while True:
        chunk = os.read(source_fd, 1024 * 1024)
        if not chunk:
            break
        total += len(chunk)
        if total > limit:
            raise RuntimeError("protected config exceeds the size limit")
        digest.update(chunk)
        view = memoryview(chunk)
        while view:
            written = os.write(snapshot_fd, view)
            view = view[written:]
    os.fsync(snapshot_fd)
finally:
    os.close(snapshot_fd)
    os.close(source_fd)
print(digest.hexdigest())
PY
}

safe_config_hash() {
  CONFIG_SOURCE_PATH="$1" /usr/bin/python3 -I - <<'PY'
import hashlib
import os
import stat

path = os.environ["CONFIG_SOURCE_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path:
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", directory_flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    file_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
finally:
    os.close(parent_fd)
try:
    metadata = os.fstat(file_fd)
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > 16 * 1024 * 1024:
        raise SystemExit(3)
    digest = hashlib.sha256()
    while True:
        chunk = os.read(file_fd, 1024 * 1024)
        if not chunk:
            break
        digest.update(chunk)
finally:
    os.close(file_fd)
print(digest.hexdigest())
PY
}

safe_config_restore() {
  snapshot_path="$1"
  destination_path="$2"
  CONFIG_SNAPSHOT_PATH="$snapshot_path" CONFIG_DESTINATION_PATH="$destination_path" /usr/bin/python3 -I - <<'PY'
import hashlib
import os
import pwd
import secrets
import stat

source_path = os.environ["CONFIG_SNAPSHOT_PATH"]
destination_path = os.environ["CONFIG_DESTINATION_PATH"]
limit = 16 * 1024 * 1024
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
file_nofollow = os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)

def open_parent(path):
    parts = [part for part in path.split("/") if part]
    if not path.startswith("/") or not parts or "\x00" in path:
        raise RuntimeError("invalid path")
    parent_fd = os.open("/", directory_flags)
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    return parent_fd, parts[-1]

source_parent_fd, source_name = open_parent(source_path)
try:
    source_fd = os.open(source_name, os.O_RDONLY | os.O_NONBLOCK | file_nofollow, dir_fd=source_parent_fd)
finally:
    os.close(source_parent_fd)
if not stat.S_ISREG(os.fstat(source_fd).st_mode):
    os.close(source_fd)
    raise SystemExit("config snapshot is not a regular file")

destination_parent_fd, destination_name = open_parent(destination_path)
temporary_name = ".mass-notifications.config.restore." + secrets.token_hex(8)
temporary_fd = -1
try:
    temporary_fd = os.open(
        temporary_name,
        os.O_WRONLY | os.O_CREAT | os.O_EXCL | file_nofollow,
        0o600,
        dir_fd=destination_parent_fd,
    )
    total = 0
    digest = hashlib.sha256()
    while True:
        chunk = os.read(source_fd, 1024 * 1024)
        if not chunk:
            break
        total += len(chunk)
        if total > limit:
            raise RuntimeError("config snapshot exceeds the size limit")
        digest.update(chunk)
        view = memoryview(chunk)
        while view:
            written = os.write(temporary_fd, view)
            view = view[written:]
    account = pwd.getpwnam("asterisk")
    os.fchmod(temporary_fd, 0o640)
    os.fchown(temporary_fd, account.pw_uid, account.pw_gid)
    os.fsync(temporary_fd)
    os.close(temporary_fd)
    temporary_fd = -1
    os.replace(temporary_name, destination_name, src_dir_fd=destination_parent_fd, dst_dir_fd=destination_parent_fd)
    os.fsync(destination_parent_fd)
    restored_fd = os.open(destination_name, os.O_RDONLY | os.O_NONBLOCK | file_nofollow, dir_fd=destination_parent_fd)
    try:
        metadata = os.fstat(restored_fd)
        if (
            not stat.S_ISREG(metadata.st_mode)
            or stat.S_IMODE(metadata.st_mode) != 0o640
            or metadata.st_uid != account.pw_uid
            or metadata.st_gid != account.pw_gid
        ):
            raise RuntimeError("restored config metadata is invalid")
        restored = hashlib.sha256()
        while True:
            chunk = os.read(restored_fd, 1024 * 1024)
            if not chunk:
                break
            restored.update(chunk)
        if restored.digest() != digest.digest():
            raise RuntimeError("restored config digest mismatch")
    finally:
        os.close(restored_fd)
finally:
    if temporary_fd >= 0:
        os.close(temporary_fd)
    try:
        os.unlink(temporary_name, dir_fd=destination_parent_fd)
    except FileNotFoundError:
        pass
    os.close(destination_parent_fd)
    os.close(source_fd)
PY
}

snapshot_pending_config() {
  PENDING_CONFIG_PATH="$DATA_DIR/mass-notifications.pending.config"
  PENDING_CONFIG_STATE=absent
  if [ -e "$PENDING_CONFIG_PATH" ] || [ -L "$PENDING_CONFIG_PATH" ]; then
    PENDING_CONFIG_SNAPSHOT="$(mktemp "$INSTALL_TEMP_ROOT/slsmassnotifyserver-pending.XXXXXX")" || return 1
    PENDING_CONFIG_HASH="$(safe_config_snapshot "$PENDING_CONFIG_PATH" "$PENDING_CONFIG_SNAPSHOT")" || {
      log 'Pending configuration is unsafe or unreadable. It was not applied or replaced.'
      return 1
    }
    PENDING_CONFIG_STATE=present
  fi
}

verify_pending_config_unchanged() {
  if [ "$PENDING_CONFIG_STATE" = present ]; then
    [ "$(safe_config_hash "$PENDING_CONFIG_PATH" 2>/dev/null || true)" = "$PENDING_CONFIG_HASH" ] || {
      log 'Pending settings changed during installation; the installer will not apply them.'
      return 1
    }
  elif [ "$PENDING_CONFIG_STATE" = absent ]; then
    [ ! -e "$PENDING_CONFIG_PATH" ] && [ ! -L "$PENDING_CONFIG_PATH" ] || {
      log 'A pending configuration appeared during installation; preserve it for administrator review.'
      return 1
    }
  fi
}

initialize_fresh_install_config() {
  [ ! -e "$CONFIG_FILE" ] && [ ! -L "$CONFIG_FILE" ] || return 0
  [ "$HAD_EXISTING_MODULE" -eq 0 ] && [ ! -d "$MODULE_BACKUP_DIR/$MODULE" ] || {
    log 'An existing module has no active configuration. Restore its protected configuration backup before upgrading; existing credentials will not be silently regenerated.'
    return 1
  }
  local initialization_result
  initialization_result="$(SLS_INIT_PHONE_CAPACITY="$FRESH_PHONE_CAPACITY" php -r '
require "/etc/freepbx.conf";
require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php";
$class = "\\FreePBX\\modules\\Slsmassnotifyserver";
$obj = new $class(\FreePBX::Create());
$capacity = getenv("SLS_INIT_PHONE_CAPACITY");
if (!preg_match("/^[0-9]{1,4}$/D", (string)$capacity) || (int)$capacity < 25 || (int)$capacity > 1000) { exit(1); }
echo $obj->initializeFreshInstallerConfiguration((int)$capacity) ? "created" : "existing";
')" || return 1
  case "$initialization_result" in
    created) FRESH_CONFIG_INITIALIZED=1 ;;
    existing) FRESH_CONFIG_INITIALIZED=0 ;;
    *) log "Fresh configuration initialization did not return a verified result."; return 1 ;;
  esac
  # No config lock was held while the file was absent. Freeze the completed
  # initial file before module installation/reload can observe it.
  acquire_settings_coordination
  snapshot_config
  verify_fresh_install_defaults
}

snapshot_config() {
  if [ -L "$CONFIG_FILE" ]; then
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  fi
  [ -r "$CONFIG_FILE" ] || return 0
  CONFIG_SNAPSHOT="$(mktemp "$INSTALL_TEMP_ROOT/slsmassnotifyserver-config.XXXXXX")"
  if ! CONFIG_HASH_BEFORE="$(safe_config_snapshot "$CONFIG_FILE" "$CONFIG_SNAPSHOT")"; then
    rm -f "$CONFIG_SNAPSHOT"
    CONFIG_SNAPSHOT=""
    log "Refusing to install because the protected central configuration could not be opened safely without following symbolic links."
    exit 1
  fi
}

validate_preserved_config_prerequisites() {
  [ -n "$CONFIG_HASH_BEFORE" ] || return 0
  if ! CONFIG_PATH="$CONFIG_SNAPSHOT" /usr/bin/php -r '
$path = getenv("CONFIG_PATH");
require_once (getenv("SLS_CONFIG_CRYPTO_PHP") ?: "/usr/local/bin/sls_mass_notify/sls_config_crypto.php");
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents($path));
if (!is_array($settings)) {
    exit(1);
}

$ami = $settings["ami"] ?? null;
if (!is_array($ami)) {
    exit(2);
}
$username = trim((string)($ami["username"] ?? ""));
$password = trim((string)($ami["password"] ?? ""));
if (!preg_match("/^[a-z0-9_.-]{1,64}$/", $username)) {
    exit(3);
}
if (!preg_match("/^[\\x21-\\x7e]{1,128}$/", $password)) {
    exit(4);
}
exit(0);
'; then
    log "The preserved central config has invalid JSON or AMI credentials. Restore a valid mass-notifications.config before upgrading; the installer did not alter it."
    exit 1
  fi
}

validate_staged_central_config() {
  [ -n "$CONFIG_HASH_BEFORE" ] || return 0
  if /usr/bin/python3 -I "$STAGING_DIR/$MODULE/bin/sls_mass_notify/sls_config.py" "$CONFIG_FILE" >/dev/null 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"; then
    return 0
  else
    local config_status=$?
    INSTALL_ERROR_CATEGORY="protected_config_validation_failed"
    log "The protected central configuration failed validation against the staged runtime. Module activation was not started."
    return "$config_status"
  fi
}

describe_config_drift() {
  before_path="$1"
  after_path="$2"
  CONFIG_BEFORE="$before_path" CONFIG_AFTER="$after_path" python3 -I - "$INSTALL_BOOTSTRAP_DIR/sls_config_crypto.py" <<'PY' 2>/dev/null || true
import json
import os

from pathlib import Path
import sys
import types
sys.dont_write_bytecode = True
path = Path(sys.argv[1])
module = types.ModuleType('sls_config_crypto'); module.__file__ = str(path)
exec(compile(path.read_bytes(), str(path), 'exec'), module.__dict__)
before = module.read_config(os.environ["CONFIG_BEFORE"])
after = module.read_config(os.environ["CONFIG_AFTER"])

changes = []
def compare(left, right, path="settings"):
    if isinstance(left, dict) and isinstance(right, dict):
        for key in sorted(set(left) | set(right)):
            child = f"{path}.{key}"
            if key not in left:
                changes.append(f"added {child}")
            elif key not in right:
                changes.append(f"removed {child}")
            else:
                compare(left[key], right[key], child)
        return
    if isinstance(left, list) and isinstance(right, list):
        if len(left) != len(right):
            changes.append(f"changed {path} length")
            return
        for index, (left_item, right_item) in enumerate(zip(left, right)):
            compare(left_item, right_item, f"{path}[{index}]")
        return
    if left != right:
        changes.append(f"changed {path}")

compare(before, after)
for item in changes[:25]:
    print(f"Config drift: {item}")
if len(changes) > 25:
    print(f"Config drift: {len(changes) - 25} additional path(s)")
if not changes:
    print("Config drift: JSON values are equivalent; formatting or key order changed")
PY
}

verify_config_unchanged() {
  [ -n "$CONFIG_HASH_BEFORE" ] || return 0
  current_hash="$(safe_config_hash "$CONFIG_FILE" 2>/dev/null || true)"
  if [ "$current_hash" = "$CONFIG_HASH_BEFORE" ]; then
    # Later postconditions can still fail. Retire this snapshot only after the
    # transaction's commit or verified rollback has been recorded durably.
    return 0
  fi
  current_snapshot="$(mktemp "$INSTALL_TEMP_ROOT/slsmassnotifyserver-config-current.XXXXXX")"
  if safe_config_snapshot "$CONFIG_FILE" "$current_snapshot" >/dev/null 2>&1; then
    describe_config_drift "$CONFIG_SNAPSHOT" "$current_snapshot"
  else
    log "Config drift: the live protected path became missing, non-regular, or unsafe."
  fi
  rm -f "$current_snapshot"
  if ! ensure_data_directory || ! safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE"; then
    log "CRITICAL: unable to safely restore the protected central config. The original root-owned snapshot remains at $CONFIG_SNAPSHOT."
    rollback_failure "protected configuration restoration failed after verification"
    exit 1
  fi
  # Keep the snapshot for the exit guard until rollback is fully verified.
  log "The installer detected an unexpected central config change, restored the original config, and stopped."
  exit 1
}

module_known() {
  fwconsole ma list 2>/dev/null | grep -Eq "\\|[[:space:]]*$1[[:space:]]*\\|"
}

stage_module_directory() {
  if { [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" = 1 ] && [ -f "/var/www/html/admin/modules/$MODULE/module.xml" ]; } || { [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" != 1 ] && module_known "$MODULE"; }; then
    HAD_EXISTING_MODULE=1
    [ -e "$CONFIG_FILE" ] || { log "An existing SLS module is missing its protected configuration. Restore a configuration backup before upgrading."; return 1; }
    if [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" != 1 ] && fwconsole ma list 2>/dev/null \
      | grep -Eq "\\|[[:space:]]*${MODULE}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|"; then
      MODULE_WAS_ENABLED=1
    fi
    log "Existing SLS Mass Notify module detected; preserving config and preparing a recoverable upgrade."
  fi
  STAGING_DIR="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-stage.XXXXXX")"
  tar -xzf "$TGZ" -C "$STAGING_DIR"
  if [ ! -d "$STAGING_DIR/$MODULE" ] || [ ! -r "$STAGING_DIR/$MODULE/module.xml" ]; then
    log "The staged module tree is incomplete."
    exit 1
  fi
  while IFS= read -r script; do
    /usr/bin/php -n -l "$script" >/dev/null || {
      log "The staged module contains an invalid PHP file: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.php' -print)
  while IFS= read -r script; do
    bash -n "$script" || {
      log "The staged module contains an invalid shell script: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.sh' -print)
  while IFS= read -r script; do
    python3 -I -c 'import pathlib,sys; compile(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"), sys.argv[1], "exec")' "$script" || {
      log "The staged module contains an invalid Python script: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.py' -print)
}

activate_staged_module() {
  MODULE_BACKUP_DIR="$(mktemp -d '/var/www/html/admin/modules/.sls-mass-notify-recovery.XXXXXX')" || return 1
  persist_install_transaction mutating || return 1
  # Activate by descriptor-relative renames: a replaced public pathname must
  # never redirect privileged copying or ownership changes to another tree.
  /usr/bin/python3 -I - "$STAGING_DIR" "$MODULE_BACKUP_DIR" <<'PYACTIVATE' || return 1
import os, pwd, stat, sys
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
account = pwd.getpwnam('asterisk')
if account.pw_uid == 0: raise RuntimeError('Asterisk UID must be nonzero')
def directory(path):
    fd = os.open('/', flags)
    try:
        for part in path.split('/'):
            if not part: continue
            if part in ('.', '..'): raise RuntimeError('unsafe module directory')
            child = os.open(part, flags, dir_fd=fd)
            os.close(fd); fd = child
        return fd
    except BaseException:
        os.close(fd); raise
stage, backup, web = (directory(path) for path in (*sys.argv[1:], '/var/www/html/admin/modules'))
try:
    for fd in (stage, backup):
        info = os.fstat(fd)
        if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700: raise RuntimeError('unsafe private module directory')
    if os.fstat(backup).st_dev != os.fstat(web).st_dev:
        raise RuntimeError('module recovery must share the installed module filesystem')
    os.mkdir('candidate', 0o700, dir_fd=backup)
    tree = os.open('candidate', flags, dir_fd=backup)
    source = os.open('slsmassnotifyserver', flags, dir_fd=stage)
    def copy_tree(src, dst):
        for name in os.listdir(src):
            before = os.stat(name, dir_fd=src, follow_symlinks=False)
            if before.st_uid != 0 or before.st_mode & 0o022:
                raise RuntimeError('unprotected staged module source')
            if stat.S_ISDIR(before.st_mode):
                os.mkdir(name, 0o700, dir_fd=dst)
                child = os.open(name, flags, dir_fd=src)
                target = os.open(name, flags, dir_fd=dst)
                try: copy_tree(child, target)
                finally: os.close(child); os.close(target)
            elif stat.S_ISREG(before.st_mode) and before.st_nlink == 1:
                child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=src)
                target = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=dst)
                try:
                    opened = os.fstat(child)
                    if (before.st_dev, before.st_ino) != (opened.st_dev, opened.st_ino):
                        raise RuntimeError('staged module changed before copy')
                    while True:
                        block = os.read(child, 65536)
                        if not block: break
                        view = memoryview(block)
                        while view:
                            count = os.write(target, view)
                            if count <= 0: raise RuntimeError('short module activation copy')
                            view = view[count:]
                    after = os.fstat(child)
                    if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                        raise RuntimeError('staged module changed during copy')
                    os.fchmod(target, 0o755 if before.st_mode & 0o111 else 0o644)
                    os.fsync(target)
                finally: os.close(child); os.close(target)
            else: raise RuntimeError('unsafe staged module entry')
        os.fsync(dst)
    try: copy_tree(source, tree)
    except BaseException: os.close(tree); raise
    finally: os.close(source)
    def prepare(fd):
        for name in os.listdir(fd):
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISDIR(info.st_mode):
                child = os.open(name, flags, dir_fd=fd)
                try: prepare(child)
                finally: os.close(child)
            elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
                try:
                    opened = os.fstat(child)
                    if (info.st_dev,info.st_ino)!=(opened.st_dev,opened.st_ino): raise RuntimeError('staged source changed')
                    os.fchown(child,account.pw_uid,account.pw_gid)
                    os.fchmod(child,0o755 if info.st_mode & 0o111 else 0o644)
                finally: os.close(child)
            else: raise RuntimeError('unsafe staged module entry')
        os.fchown(fd,account.pw_uid,account.pw_gid); os.fchmod(fd,0o755)
    try: prepare(tree)
    finally: os.close(tree)
    prior=False
    try: info=os.stat('slsmassnotifyserver',dir_fd=web,follow_symlinks=False)
    except FileNotFoundError: pass
    else:
        if not stat.S_ISDIR(info.st_mode): raise RuntimeError('installed module directory is unsafe')
        os.rename('slsmassnotifyserver','slsmassnotifyserver',src_dir_fd=web,dst_dir_fd=backup)
        prior=True
    try: os.rename('candidate','slsmassnotifyserver',src_dir_fd=backup,dst_dir_fd=web)
    except BaseException:
        if prior: os.rename('slsmassnotifyserver','slsmassnotifyserver',src_dir_fd=backup,dst_dir_fd=web)
        raise
    marker = os.open('module-swap-complete', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=backup)
    os.fsync(marker); os.close(marker)
    os.fsync(web); os.fsync(backup)
finally:
    for fd in (stage,backup,web): os.close(fd)
PYACTIVATE
  MODULE_ACTIVATED=1
}

sync_module_version() {
  php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE") ?: "slsmassnotifyserver";
$xmlPath = "/var/www/html/admin/modules/" . $module . "/module.xml";
if (!is_readable($xmlPath)) {
    exit(1);
}
$xml = simplexml_load_file($xmlPath);
$version = $xml && isset($xml->version) ? trim((string)$xml->version) : "";
if ($version === "") {
    exit(1);
}
$db = \FreePBX::Database();
$stmt = $db->prepare("UPDATE modules SET version = ? WHERE modulename = ?");
$stmt->execute([$version, $module]);
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

module_registered_at_expected_version() {
  SLS_MASS_NOTIFY_MODULE="$MODULE" php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE") ?: "slsmassnotifyserver";
$stmt = \FreePBX::Database()->prepare("SELECT version FROM modules WHERE modulename = ? LIMIT 1");
$stmt->execute([$module]);
$version = $stmt->fetchColumn();
exit(is_string($version) && trim($version) === "0.1.6-beta" ? 0 : 1);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

refresh_module_install() {
  log "Refreshing SLS Mass Notify runtime integration."
  if ! SLS_MASS_NOTIFY_DEFER_SIGNING=1 php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "Direct runtime refresh failed. See $LOG_FILE."
    return 1
  fi
}

runtime_install_postconditions_available() {
  local executable
  for executable in \
    /usr/local/bin/slsconsole \
    /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh \
    /usr/local/bin/sls_mass_notify/sls_notify.py \
    /usr/local/bin/sls_mass_notify/sls_config.py \
    /usr/local/bin/sls_mass_notify/piper/venv/bin/piper \
    /usr/local/bin/piper \
    "$DATA_DIR/piper/venv/bin/piper"; do
    if [ ! -x "$executable" ]; then
      log "Runtime prerequisite is missing or not executable: $executable"
      return 1
    fi
  done
  MODULE_ROOT="/var/www/html/admin/modules/$MODULE" python3 -I - <<'PY'
import filecmp
import os
import pathlib

module = pathlib.Path(os.environ["MODULE_ROOT"])
runtime = pathlib.Path("/usr/local/bin/sls_mass_notify")
expected = {}
for name in (
    "sls_mass_notify_nws_poll.sh",
    "sls_mass_notify_weather_poll.sh",
    "sls_mass_notify_schedule_worker.php",
    "sls_mass_notify_announcement_worker.php",
    "sls_mass_notify_automation_worker.php",
    "sls_mass_notify_cluster_worker.php",
    "sls_mass_notify_cluster_effect.php",
    "sls_mass_notify_delivery_authorization.php",
    "sls_mass_notify_panic.php",
    "sls_mass_notify_live_paging.php",
    "sls_mass_notify_weather_channels.php",
    "sls_mass_notify_phone_agi.py",
    "sls_mass_notify_test.sh",
    "sls_mass_notify_update.sh",
    "sls_mass_notify_maintenance.sh",
    "sls_mass_notify_uninstall.sh",
    "sls_mass_notify_install_piper_voices.sh",
):
    expected[pathlib.PurePosixPath(name)] = module / "bin" / name
source_root = module / "bin" / "sls_mass_notify"
if not source_root.is_dir():
    print("Runtime source directory is missing: bin/sls_mass_notify")
    raise SystemExit(1)
for source in source_root.rglob("*"):
    if source.is_file() and "__pycache__" not in source.parts and source.suffix != ".pyc":
        expected[pathlib.PurePosixPath(source.relative_to(source_root).as_posix())] = source
actual = {
    pathlib.PurePosixPath(path.relative_to(runtime).as_posix())
    for path in runtime.rglob("*")
    if path.is_file()
    and path.relative_to(runtime).parts[:1] != ("piper",)
    and "__pycache__" not in path.parts
    and path.suffix != ".pyc"
}
if actual != set(expected):
    for label, paths in (("missing", set(expected) - actual), ("unexpected", actual - set(expected))):
        for path in sorted(paths)[:12]:
            print(f"Runtime inventory {label}: {str(path)!r}")
    raise SystemExit(1)
for relative, source in expected.items():
    target = runtime / pathlib.Path(str(relative))
    if not target.is_file() or not filecmp.cmp(source, target, shallow=False):
        print(f"Runtime file does not match the installed package: {str(relative)!r}")
        raise SystemExit(1)
PY
}

ensure_runtime_installed() {
  protected_install_phase admit || return 1
  runtime_install_postconditions_available || {
    log "Authenticated runtime postconditions are incomplete. Preserve recovery files; the installer will not execute an older module hook to repair privileged files."
    return 1
  }
}

install_module_with_autoenable() {
  local install_output install_status

  if install_output="$(run_without_install_maintenance_lock fwconsole ma install --autoenable "$MODULE" 2>&1)"; then
    printf '%s\n' "$install_output" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
    return 0
  else
    install_status=$?
  fi
  printf '%s\n' "$install_output" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"

  if ! printf '%s\n' "$install_output" | grep -Eqi \
    '(unknown|unrecognized|invalid|no such)[[:space:]-]+option[^[:cntrl:]]*autoenable|option[^[:cntrl:]]*autoenable[^[:cntrl:]]*(does not exist|is not defined|is unknown|is unrecognized)|autoenable[^[:cntrl:]]*option[^[:cntrl:]]*(does not exist|is not defined|is unknown|is unrecognized)'; then
    return "$install_status"
  fi

  log "This fwconsole build requires global options before the module action; retrying compatible --autoenable syntax."
  run_without_install_maintenance_lock fwconsole ma --autoenable install "$MODULE" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

verify_runtime_shell_syntax() {
  local runtime_dir="${1:-/usr/local/bin/sls_mass_notify}"
  local script
  for script in sls_mass_notify_nws_poll.sh sls_mass_notify_weather_poll.sh \
    sls_mass_notify_test.sh sls_mass_notify_update.sh sls_mass_notify_maintenance.sh \
    sls_mass_notify_uninstall.sh sls_mass_notify_install_piper_voices.sh; do
    if [ ! -x "$runtime_dir/$script" ] || ! /bin/bash -n "$runtime_dir/$script" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
      INSTALL_ERROR_CATEGORY="update_script_syntax_error"
      log "Required runtime shell script is missing, not executable, or invalid: $script"
      return 2
    fi
  done
}

verify_announcement_worker_health() {
  local runtime_dir="${1:-/usr/local/bin/sls_mass_notify}"
  /usr/bin/php -l "$runtime_dir/sls_announcement_jobs.php" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || return $?
  /usr/bin/php -l "$runtime_dir/sls_mass_notify_announcement_worker.php" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || return $?
  run_without_install_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/timeout 45 \
    /usr/bin/php "$runtime_dir/sls_mass_notify_announcement_worker.php" --health-check --record-health >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

ensure_piper_runtime() {
  protected_install_phase dependencies --apply
}

verify_piper_voices() {
  local voice_dir="$DATA_DIR/piper/voices"
  local synthesis_file model
  while read -r expected filename; do
    printf '%s  %s\n' "$expected" "$voice_dir/$filename" | sha256sum -c - >/dev/null || {
      log "Required Piper voice failed checksum validation: $filename"
      exit 1
    }
  done <<'EOF'
f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375 en_US-lessac-low.onnx
45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a en_US-lessac-low.onnx.json
a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3 en_US-amy-low.onnx
2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb en_US-amy-low.onnx.json
8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf en_US-ryan-low.onnx
b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60 en_US-ryan-low.onnx.json
EOF
  for model in en_US-lessac-low.onnx en_US-amy-low.onnx en_US-ryan-low.onnx; do
    synthesis_file="$DATA_DIR/sounds/tts/installer-voice-check-${model%.onnx}-$$.wav"
    rm -f "$synthesis_file"
    if ! printf '%s\n' 'SLS voice check.' | runuser -u asterisk -- /usr/bin/timeout 90 /usr/local/bin/piper \
      --model "$voice_dir/$model" --output-file "$synthesis_file" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
      rm -f "$synthesis_file"
      log "Piper could not synthesize audio with $model. See $LOG_FILE."
      exit 1
    fi
    soxi "$synthesis_file" >/dev/null 2>&1 || {
      rm -f "$synthesis_file"
      log "Piper produced an invalid WAV with $model."
      exit 1
    }
    rm -f "$synthesis_file"
  done
}

repair_runtime_permissions() {
  # Permission repair and promotion are one authenticated, bounded operation.
  # This replaces recursive ownership repair of arbitrary web-owned files.
  protected_install_phase prepare --apply
}

secure_central_config() {
  if ! CONFIG_PATH="$CONFIG_FILE" /usr/bin/python3 -I - <<'PY'
import os
import pwd
import stat

path = os.environ["CONFIG_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path:
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", directory_flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    file_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
finally:
    os.close(parent_fd)
try:
    metadata = os.fstat(file_fd)
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise SystemExit(3)
    account = pwd.getpwnam("asterisk")
    os.fchmod(file_fd, 0o640)
    os.fchown(file_fd, account.pw_uid, account.pw_gid)
    verified = os.fstat(file_fd)
    if stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_uid != account.pw_uid or verified.st_gid != account.pw_gid:
        raise SystemExit(4)
finally:
    os.close(file_fd)
PY
  then
    log "Protected central configuration permissions or ownership could not be secured."
    exit 1
  fi
}

validate_ami_health_file() {
  python3 -I - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    health = json.load(handle)
if health.get("status") != "ok" or health.get("ami") != "authenticated" or health.get("ping") != "pong":
    raise SystemExit("AMI health result is incomplete")
if health.get("pjsip_show_contacts") not in {"authorized", "authorized_empty"}:
    raise SystemExit("AMI PJSIPShowContacts authorization was not confirmed")
if health.get("pjsip_notify") != "authorized":
    raise SystemExit("AMI PJSIPNotify authorization was not confirmed")
PY
}

verify_ami_with_repair() (
  local attempt probe_dir
  probe_dir="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-ami-check.XXXXXX")" || {
    log "Unable to create private AMI verification storage. Check free space and /tmp permissions."
    return 1
  }
  trap 'rm -f -- "$probe_dir/health.json" "$probe_dir/health.err"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  for attempt in 1 2 3; do
    if /usr/bin/timeout 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py --ami-health-json \
      >"$probe_dir/health.json" 2>"$probe_dir/health.err"; then
      if validate_ami_health_file "$probe_dir/health.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
      then
        return 0
      fi
    fi
    cat "$probe_dir/health.err" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    log "SLS AMI health check attempt $attempt failed; rebuilding the loopback manager integration."
    refresh_module_install >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    asterisk -rx "manager reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    sleep 2
  done
  log "SLS AMI authentication, Ping, PJSIPShowContacts, or PJSIPNotify authorization failed after automatic repair. See $LOG_FILE."
  return 1
)

validate_phone_collector_probe_file() {
  python3 -I - "$1" "$2" <<'PY'
import json
import math
import sys

kind, path = sys.argv[1:]
with open(path, encoding="utf-8") as handle:
    result = json.load(handle)
if not isinstance(result, dict) or result.get("ok") is not True:
    raise SystemExit("Phone runtime probe failed; review the collector service and local AMI permissions.")
if kind == "ami":
    if result.get("ping") is not True or result.get("show_dialplan") is not True:
        raise SystemExit("AMI Ping/ShowDialPlan authorization was not confirmed; the SLS account requires reporting permission.")
elif kind == "health":
    age = result.get("heartbeat_age_seconds")
    if (result.get("connected") is not True or type(age) not in (int, float)
            or not math.isfinite(age) or not 0 <= age <= 15
            or any(type(result.get(key)) is not int or result[key] < 0 for key in ("active_batches", "reserved_contacts"))):
        raise SystemExit("The phone collector has no valid current heartbeat; new phone audio admission remains blocked.")
else:
    raise SystemExit("Unknown phone runtime probe.")
PY
}

verify_phone_collector() (
  local probe_dir attempt
  systemctl is-enabled --quiet sls-mass-notify-phone-events.service && \
    systemctl is-active --quiet sls-mass-notify-phone-events.service || {
      log "The phone outcome collector must be enabled and active. Review systemctl status sls-mass-notify-phone-events.service, then run Repair Installation."
      return 1
    }
  probe_dir="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-phone-collector-check.XXXXXX")" || return 1
  trap 'rm -f -- "$probe_dir/ami.json" "$probe_dir/health.json"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  if ! /usr/bin/timeout --kill-after=1 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I \
      /usr/local/bin/sls_mass_notify/sls_phone_admission.py --probe-ami >"$probe_dir/ami.json" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" \
    || ! validate_phone_collector_probe_file ami "$probe_dir/ami.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "The SLS phone runtime could not confirm AMI Ping and ShowDialPlan reporting authorization. Review the local AMI connection and run Repair Installation."
    return 1
  fi
  for attempt in 1 2 3; do
    if /usr/bin/timeout --kill-after=1 5 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I \
        /usr/local/bin/sls_mass_notify/sls_phone_events.py --health >"$probe_dir/health.json" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" \
      && validate_phone_collector_probe_file health "$probe_dir/health.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
      return 0
    fi
    sleep 2
  done
  log "The phone outcome collector has no fresh authenticated heartbeat. New phone audio admission remains blocked; inspect its service journal and private data permissions."
  return 1
)

verify_pjsip_contact_inventory() (
  local probe_dir
  probe_dir="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-contact-check.XXXXXX")" || {
    log "Unable to create private PJSIP verification storage. Check free space and /tmp permissions."
    return 1
  }
  trap 'rm -f -- "$probe_dir/contacts.out"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  if ! asterisk -rx "pjsip show contacts" >"$probe_dir/contacts.out" 2>&1; then
    cat "$probe_dir/contacts.out" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    log "Asterisk could not return the PJSIP contact inventory. See $LOG_FILE."
    return 1
  fi
)

verify_local_api_route() (
  local path="$1" expected_pattern="$2" expected_codes="$3" label="$4"
  local probe_dir code
  probe_dir="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-api-check.XXXXXX")" || {
    log "Unable to create private API verification storage. Check free space and /tmp permissions."
    return 1
  }
  trap 'rm -f -- "$probe_dir/response.out"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  code="$(local_web_probe "$path" "$probe_dir/response.out" "$expected_pattern" || true)"
  if [[ ! "$code" =~ $expected_pattern ]]; then
    # A failed route may return a login page or PHP exception. Do not retain
    # that potentially sensitive response body in a shared temporary file.
    log "$label expected HTTP $expected_codes for $path, got $code. Check routing on the detected local Apache listener ports and the application error log."
    return 1
  fi
)

verify_served_php_runtime() (
  local directory code
  directory="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-served-php.XXXXXX")" || return 1
  trap 'rm -f -- "$directory/response.json"; rmdir -- "$directory" 2>/dev/null || true' EXIT
  code="$(local_web_probe '/api/sls-mass-notify/?sls_installer_runtime=1' "$directory/response.json" '^(200|503)$' || true)"
  /usr/bin/python3 -I - "$directory/response.json" "$code" <<'PY'
import json, sys
from pathlib import Path
try:
    result = json.loads(Path(sys.argv[1]).read_bytes())
    assert result.get('probe') == 'sls_served_php_v1'
    assert isinstance(result.get('missing'), list)
except (OSError, ValueError, AssertionError):
    raise SystemExit('The served PHP runtime probe did not reach SLS. Check the local virtual host, API route and PHP handler; CLI PHP alone is insufficient.')
if result.get('ok') is not True or sys.argv[2] != '200':
    missing = [name for name in result['missing'] if name in ('pdo_sqlite','curl','dom','mbstring','posix','openssl','sodium','php_8_2_or_newer','exec_enabled','proc_open_enabled','posix_geteuid_enabled','posix_getpwnam_enabled','openssl_encrypt_enabled','openssl_decrypt_enabled','sodium_crypto_aead_xchacha20poly1305_ietf_encrypt_enabled','sodium_crypto_pwhash_str_enabled','web_runtime_must_run_as_asterisk')]
    raise SystemExit('The PHP runtime serving Apache is missing: ' + ', '.join(missing) + '. Enable these for the active Apache/FPM PHP version and retry; installing CLI extensions alone does not repair another PHP handler.')
print('Served PHP prerequisites verified through the local API route.')
PY
)

verify_dashboard_integration() {
  local source_section="/var/www/html/admin/modules/$MODULE/dashboard/sections/SlsMassNotifyAnnouncement.class.php"
  local source_view="/var/www/html/admin/modules/$MODULE/dashboard/views/sections/sls-mass-notify-announcement.php"
  local dashboard_section="/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php"
  local dashboard_view="/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php"

  cmp -s "$source_section" "$dashboard_section" || {
    log "Dashboard announcement section is missing or does not match the installed module."
    exit 1
  }
  cmp -s "$source_view" "$dashboard_view" || {
    log "Dashboard announcement view is missing or does not match the installed module."
    exit 1
  }

  php -r '
require "/etc/freepbx.conf";
$dashboard = \FreePBX::Dashboard();
$hooks = $dashboard->getConfig("allhooks");
$found = false;
foreach ((array)$hooks as $page) {
    foreach ((array)($page["entries"] ?? []) as $entry) {
        if (($entry["rawname"] ?? "") === "SlsMassNotifyAnnouncement"
            && ($entry["section"] ?? "") === "sls_mass_notify_announcement") {
            $found = true;
            break 2;
        }
    }
}
if (!$found) {
    fwrite(STDERR, "Mass Notify announcement hook is absent from the persisted Dashboard index.\n");
    exit(1);
}
require_once "/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php";
$section = new \FreePBX\modules\Dashboard\Sections\SlsMassNotifyAnnouncement();
$html = $section->getContent("sls_mass_notify_announcement");
if (strpos($html, "dashboard-sls-mass-notify-announcement") === false
    || strpos($html, "Unable to load Mass Notify") !== false) {
    fwrite(STDERR, "Mass Notify announcement panel did not render successfully.\n");
    exit(1);
}
echo "Mass Notify Dashboard announcement panel verified (" . strlen($html) . " bytes).\n";
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log "Dashboard announcement hook or panel rendering verification failed. See $LOG_FILE."
    exit 1
  }
}

verify_installed_payload_parity() {
  MODULE_ROOT="/var/www/html/admin/modules/$MODULE" python3 -I - <<'PY'
import filecmp
import os
import pathlib
import stat

module = pathlib.Path(os.environ["MODULE_ROOT"])
runtime = pathlib.Path("/usr/local/bin/sls_mass_notify")
expected_runtime = {}
for name in (
    "sls_mass_notify_nws_poll.sh",
    "sls_mass_notify_weather_poll.sh",
    "sls_mass_notify_schedule_worker.php",
    "sls_mass_notify_announcement_worker.php",
    "sls_mass_notify_automation_worker.php",
    "sls_mass_notify_cluster_worker.php",
    "sls_mass_notify_cluster_effect.php",
    "sls_mass_notify_delivery_authorization.php",
    "sls_mass_notify_panic.php",
    "sls_mass_notify_live_paging.php",
    "sls_mass_notify_weather_channels.php",
    "sls_mass_notify_phone_agi.py",
    "sls_mass_notify_test.sh",
    "sls_mass_notify_update.sh",
    "sls_mass_notify_maintenance.sh",
    "sls_mass_notify_uninstall.sh",
    "sls_mass_notify_install_piper_voices.sh",
):
    expected_runtime[pathlib.PurePosixPath(name)] = module / "bin" / name
for source in (module / "bin" / "sls_mass_notify").rglob("*"):
    if not source.is_file() or "__pycache__" in source.parts or source.suffix == ".pyc":
        continue
    relative = pathlib.PurePosixPath(source.relative_to(module / "bin" / "sls_mass_notify").as_posix())
    expected_runtime[relative] = source

actual_runtime = {
    pathlib.PurePosixPath(path.relative_to(runtime).as_posix())
    for path in runtime.rglob("*")
    if path.is_file()
    and path.relative_to(runtime).parts[:1] != ("piper",)
    and "__pycache__" not in path.parts
    and path.suffix != ".pyc"
}
if actual_runtime != set(expected_runtime):
    missing = sorted(str(path) for path in set(expected_runtime) - actual_runtime)
    extra = sorted(str(path) for path in actual_runtime - set(expected_runtime))
    raise SystemExit(f"runtime manifest mismatch; missing={missing} extra={extra}")
for relative, source in expected_runtime.items():
    target = runtime / pathlib.Path(str(relative))
    if not target.is_file() or not filecmp.cmp(source, target, shallow=False):
        raise SystemExit(f"runtime file differs from packaged source: {relative}")

for source_root, target_root, label in (
    (module / "api" / "sipnotify", pathlib.Path("/var/www/html/api/sipnotify"), "desktop API"),
    (module / "api" / "sls-mass-notify", pathlib.Path("/var/www/html/api/sls-mass-notify"), "control API"),
    (module / "portal", pathlib.Path("/var/www/html/mass-notify"), "operator portal"),
    (module / "assets", pathlib.Path("/var/www/html/sls_mass_notify/assets"), "public assets"),
):
    expected = {
        pathlib.PurePosixPath(path.relative_to(source_root).as_posix()): path
        for path in source_root.rglob("*")
        if path.is_file()
    }
    actual = {
        pathlib.PurePosixPath(path.relative_to(target_root).as_posix())
        for path in target_root.rglob("*")
        if path.is_file()
    }
    if actual != set(expected):
        raise SystemExit(f"{label} manifest differs from packaged source")
    for relative, source in expected.items():
        target = target_root / pathlib.Path(str(relative))
        if not target.is_file() or not filecmp.cmp(source, target, shallow=False):
            raise SystemExit(f"{label} file differs from packaged source: {relative}")

signer_source = module / "bin" / "sign_sls_mass_notify_local_sig.sh"
signer_target = pathlib.Path("/usr/local/sbin/sign_sls_mass_notify_local_sig.sh")
if not signer_target.is_file() or not filecmp.cmp(signer_source, signer_target, shallow=False):
    raise SystemExit("local signer differs from packaged source")
console_source = module / "bin" / "slsconsole"
console_target = pathlib.Path("/usr/local/bin/slsconsole")
console_info = console_target.lstat()
if not stat.S_ISREG(console_info.st_mode) or console_info.st_uid != 0 or console_info.st_nlink != 1 or stat.S_IMODE(console_info.st_mode) != 0o755 or not filecmp.cmp(console_source, console_target, shallow=False):
    raise SystemExit("SLS console differs from its protected packaged source")
PY
}

verify_desktop_sse_handshake() {
  local desktop_status=0
  php -r '
$path = "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config";
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents($path));
if (!is_array($settings) || !function_exists("openssl_decrypt")) {
    exit(1);
}
$selected = null;
foreach ((array)($settings["desktop_clients"] ?? []) as $client) {
    if (is_array($client) && !empty($client["enabled"])) {
        $selected = $client;
        break;
    }
}
if (!is_array($selected)) {
    exit(3);
}
$key = base64_decode((string)($settings["desktop_auth_key"] ?? ""), true);
if (!is_string($key) || strlen($key) !== 32) {
    exit(1);
}
$encoded = (string)($selected["password_enc"] ?? "");
$raw = strpos($encoded, "v1:") === 0 ? base64_decode(substr($encoded, 3), true) : false;
if (!is_string($raw) || strlen($raw) < 29) {
    exit(1);
}
$password = openssl_decrypt(
    substr($raw, 28),
    "aes-256-gcm",
    $key,
    OPENSSL_RAW_DATA,
    substr($raw, 0, 12),
    substr($raw, 12, 16)
);
$username = (string)($selected["username"] ?? "");
if (!is_string($password) || $password === "" || $username === "") {
    exit(1);
}
require_once "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
foreach (\FreePBX\modules\SlsLocalWebProbe::responses(
    (string)($settings["public_pbx_host"] ?? ""),
    "/api/sipnotify/desktop/stream?stream_seconds=1",
    ["Authorization: Basic " . base64_encode($username . ":" . $password), "Accept: text/event-stream"],
    8
) as $response) {
    $headers = implode("\n", (array)$response["headers"]);
    $body = (string)$response["body"];
    if ((int)$response["status"] === 200
        && stripos($headers, "Content-Type: text/event-stream") !== false
        && strpos($body, "event: authenticated") !== false
        && strpos($body, "\"transport\":\"live_sse\"") !== false) {
        exit(0);
    }
}
exit(1);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || desktop_status=$?
  if [ "$desktop_status" -eq 0 ]; then
    log "Desktop live SSE authentication handshake verified."
    return 0
  fi
  if [ "$desktop_status" -eq 3 ]; then
    log "Desktop live SSE handshake skipped because all desktop clients are disabled."
    return 0
  fi
  log "Desktop live SSE authentication failed on the detected local Apache ports. Check virtual-host routing, Authorization-header forwarding, and $LOG_FILE."
  return 1
}

verify_media_access() {
  if ! runuser -u asterisk -- /usr/bin/php -r '
require_once "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
require_once "/var/www/html/api/sls-mass-notify/media-policy.php";
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config", false, null, 0, 3145729));
if (!is_array($settings)) { fwrite(STDERR, "Media policy cannot read the protected configuration.\n"); exit(1); }
$result = \FreePBX\modules\SlsLocalWebProbe::mediaAccess($settings);
if (!$result["ok"]) { fwrite(STDERR, $result["message"] . "\n"); exit(1); }
' >> "${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log 'Generated-media access enforcement could not be verified. Review the preceding policy/Apache routing error before retrying installation.'
    return 1
  fi
}

verify_control_api_authentication() {
  local control_status=0
  php -r '
$path = "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config";
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents($path));
$control = is_array($settings["control_api"] ?? null) ? $settings["control_api"] : [];
if (empty($control["enabled"])) {
    exit(3);
}
$key = trim((string)($control["api_key"] ?? ""));
if ($key === "") {
    exit(1);
}
require_once "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
$lastError = "";
foreach (\FreePBX\modules\SlsLocalWebProbe::responses(
    (string)($settings["public_pbx_host"] ?? ""),
    "/api/sls-mass-notify/?resource=status",
    ["Authorization: Bearer " . $key, "Accept: application/json"],
    8
) as $response) {
    $decoded = json_decode((string)$response["body"], true);
    if ((int)$response["status"] === 200 && is_array($decoded)
        && !empty($decoded["ok"]) && ($decoded["resource"] ?? "") === "status") {
        exit(0);
    }
    if (is_array($decoded) && ($decoded["error"] ?? "") === "ip_not_allowed") {
        exit(4);
    }
    if (is_array($decoded) && ($decoded["error"] ?? "") === "rate_limited") {
        exit(5);
    }
    if (is_array($decoded)) {
        $lastError = (string)($decoded["error"] ?? "");
    }
}
exit($lastError === "unauthorized" ? 2 : 1);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || control_status=$?
  case "$control_status" in
    0)
      log "Control API authenticated status request verified."
      ;;
    3)
      log "Control API authenticated request skipped because the API is disabled."
      ;;
    4)
      log "Control API authenticated request skipped because the configured allowlist excludes loopback."
      ;;
    5)
      log "Control API authenticated request skipped because the configured rate limit is already exhausted."
      ;;
    *)
      log "Control API authentication or Authorization-header forwarding failed. See $LOG_FILE."
      return 1
      ;;
  esac
}

verify_fresh_install_defaults() {
  [ -z "$CONFIG_HASH_BEFORE" ] || [ "$FRESH_CONFIG_INITIALIZED" -eq 1 ] || return 0
  CONFIG_PATH="$CONFIG_FILE" SLS_INIT_PHONE_CAPACITY="$FRESH_PHONE_CAPACITY" php -r '
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents(getenv("CONFIG_PATH")));
if (!is_array($settings)) {
    exit(1);
}
$setup = is_array($settings["setup"] ?? null) ? $settings["setup"] : [];
$lightning = is_array($settings["xweather"] ?? null) ? $settings["xweather"] : [];
$control = is_array($settings["control_api"] ?? null) ? $settings["control_api"] : [];
$ami = is_array($settings["ami"] ?? null) ? $settings["ami"] : [];
$checks = [
    (int)($settings["phone_device_limit"] ?? 0) === (int)getenv("SLS_INIT_PHONE_CAPACITY"),
    (int)($settings["desktop_client_limit"] ?? 0) === 25,
    (string)($settings["enabled"] ?? "") === "0",
    (string)($setup["completed"] ?? "") === "0",
    (string)($setup["beta_accepted"] ?? "") === "0",
    (string)($lightning["enabled"] ?? "") === "0",
    (int)($lightning["query_interval_minutes"] ?? 0) === 5,
    (string)($lightning["adaptive_free_tier"] ?? "") === "1",
    (int)($lightning["adaptive_grace_minutes"] ?? 0) === 60,
    (string)($lightning["quiet_hours_enabled"] ?? "") === "0",
    (int)($lightning["tts_volume"] ?? 0) === 25,
    (int)($settings["nws_tts_volume"] ?? 0) === 25,
    (int)($settings["announcement_tts_volume"] ?? 0) === 25,
    (string)($settings["announcement_timeout_mode"] ?? "") === "none",
    (int)($settings["announcement_timeout_seconds"] ?? 0) === 300,
    is_array($settings["scheduled_announcements"] ?? null) && count($settings["scheduled_announcements"]) === 0,
    basename((string)($settings["nws_piper_voice"] ?? "")) === "en_US-amy-low.onnx",
    basename((string)($settings["announcement_piper_voice"] ?? "")) === "en_US-lessac-low.onnx",
    (string)($settings["opening_tone"] ?? "") === "opening_Paging_Tone_Opening",
    (string)($settings["closing_tone"] ?? "") === "closing_Paging_Tone_Closing",
    (string)($settings["nws_opening_tone"] ?? "") === "opening_NWS_alert",
    (string)($settings["nws_closing_tone"] ?? "invalid") === "",
    (string)($lightning["opening_tone"] ?? "") === "opening_Lightning_alert",
    (string)($lightning["closing_tone"] ?? "invalid") === "",
    (string)($control["enabled"] ?? "") === "0",
    preg_match("/^[A-Za-z0-9_-]{24,128}$/", (string)($control["api_key"] ?? "")) === 1,
    preg_match("/^[A-Za-z0-9_-]{24,128}$/", (string)($ami["password"] ?? "")) === 1,
];
exit(!in_array(false, $checks, true) ? 0 : 1);
' || {
    log "Fresh central configuration does not contain the required safe defaults."
    exit 1
  }
}

ensure_local_signer() {
  # The root signer is promoted only from the authenticated immutable generation.
  # Never copy executable bytes out of the web-writable installed module tree.
  protected_install_phase admit
}

sign_and_verify_touched_modules() {
  local signer="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
  local module_name attempt signed
  local -a modules_to_sign

  ensure_local_signer || exit 1

  modules_to_sign=("$MODULE")
  [ -d /var/www/html/admin/modules/dashboard ] && modules_to_sign+=("dashboard")
  [ -d /var/www/html/admin/modules/framework ] && modules_to_sign+=("framework")

  for module_name in "${modules_to_sign[@]}"; do
    signed=0
    for attempt in 1 2; do
      if run_without_install_maintenance_lock /usr/bin/timeout --signal=TERM 360 "$signer" "$module_name" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
        signed=1
        break
      fi
      log "Local signing attempt $attempt of 2 failed for $module_name."
      [ "$attempt" -eq 2 ] || sleep 2
    done
    [ "$signed" -eq 1 ] || {
      log "Unable to locally sign $module_name. See $LOG_FILE."
      tail -100 "$LOG_FILE" 2>/dev/null || true
      exit 1
    }

    SLS_MASS_NOTIFY_MODULE="$module_name" run_without_install_maintenance_lock php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE");
$gpg = \FreePBX::GPG();
$gpg->timeout = 30;
$result = $gpg->verifyModule($module);
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
$valid = is_array($result)
    && array_key_exists("status", $result)
    && is_int($result["status"])
    && $result["status"] === 129
    && array_key_exists("details", $result)
    && is_array($result["details"])
    && count($result["details"]) === 0;
if (!$valid) {
    exit(1);
}
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
      log "FreePBX signature verification did not return trusted/good for $module_name. See $LOG_FILE."
      tail -60 "$LOG_FILE" 2>/dev/null || true
      exit 1
    }
  done
}

verify_install_cron() {
  # Validate only our jobs; never print unrelated cron contents (which may
  # contain administrator credentials). Exact commands include the outer
  # redirection that prevents cron from mailing interpreter/logging failures.
  local root_jobs="$1" user_jobs="$2" role jobs line expected job count
  local runner='/usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_cron_runner.py'
  for role in root asterisk; do
    if [ "$role" = root ]; then jobs="$root_jobs"; else jobs="$user_jobs"; fi
    for job in maintenance update weather scheduling announcement_reconcile; do
      expected="* * * * * $runner $job >/dev/null 2>&1"
      [ "$job" != update ] || expected="17 * * * * $runner $job >/dev/null 2>&1"
      count="$(printf '%s\n' "$jobs" | grep -Fxc -- "$expected" || true)"
      if { [ "$role" = root ] && [[ "$job" = maintenance || "$job" = update ]]; } \
        || { [ "$role" = asterisk ] && [[ "$job" = weather || "$job" = scheduling || "$job" = announcement_reconcile ]]; }; then
        [ "$count" -eq 1 ] || { log "Expected exactly one canonical silent $role SLS $job cron entry; found $count."; return 1; }
      else
        [ "$count" -eq 0 ] || { log "An SLS $job cron entry is assigned to the wrong runtime account."; return 1; }
      fi
    done
    while IFS= read -r line; do
      [[ "$line" =~ ^[[:space:]]*# ]] && continue
      case "$line" in
        *'/usr/local/bin/sls_mass_notify/'*|*'sls_mass_notify_'*'.sh'*|*'sls_mass_notify_'*'.php'*|*'sls_cron_runner.py'*|*'nws_weather_alert.sh'*|*'nwsalerts_ensure_menu_patch.sh'*)
          case "$role:$line" in
            "root:* * * * * $runner maintenance >/dev/null 2>&1"|"root:17 * * * * $runner update >/dev/null 2>&1"|"asterisk:* * * * * $runner weather >/dev/null 2>&1"|"asterisk:* * * * * $runner scheduling >/dev/null 2>&1"|"asterisk:* * * * * $runner announcement_reconcile >/dev/null 2>&1") ;;
            *) log "A legacy, duplicate variant, or unsafe SLS cron entry remains in the $role crontab."; return 1 ;;
          esac
          ;;
      esac
    done <<<"$jobs"
  done
}

verify_install() {
  protected_install_phase verify
  secure_central_config
  verify_installed_payload_parity
  verify_fresh_install_defaults
  sign_and_verify_touched_modules
  verify_dashboard_integration
  php -l /var/www/html/admin/views/menu_items.php >/dev/null || {
    log "FreePBX menu template is invalid after Mass Notify placement was applied."
    exit 1
  }
  grep -Fq 'SLS Mass Notifications menu placement: keep Mass Notify after UCP/User Panel.' \
    /var/www/html/admin/views/menu_items.php || {
      log "This FreePBX menu template is not compatible with the Mass Notify top-level placement patch."
      exit 1
    }
  module_list="$(fwconsole ma list)"
  printf '%s\n' "$module_list" | grep -Ei 'slsmassnotifyserver|dashboard|framework|Module'
  printf '%s\n' "$module_list" | grep -Eq '\|[[:space:]]*slsmassnotifyserver[[:space:]]*\|[^|]*\|[[:space:]]*Enabled[[:space:]]*\|' || {
    log "The SLS Mass Notify module is not enabled after installation."
    exit 1
  }
  for required_module in framework dashboard backup recordings; do
    printf '%s\n' "$module_list" \
      | grep -Eq "\\|[[:space:]]*${required_module}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|" || {
        log "Required FreePBX module is not enabled after installation: $required_module"
        exit 1
      }
  done
  if ! php -r '
require "/etc/freepbx.conf";
$freepbx = \FreePBX::Create();
if (!$freepbx->Modules->checkStatus("backup")) {
    fwrite(STDERR, "FreePBX Backup is not enabled.\n");
    exit(1);
}
$available = $freepbx->Backup->getModules();
if (!is_array($available) || !isset($available["slsmassnotifyserver"])) {
    fwrite(STDERR, "FreePBX did not discover the Mass Notify native backup adapter.\n");
    exit(2);
}
$enrollment = $freepbx->Slsmassnotifyserver->ensureFreePbxBackupEnrollment();
$health = $freepbx->Slsmassnotifyserver->getFreePbxBackupHealth();
$jobs = (int)($enrollment["jobs"] ?? 0);
$enrolled = (int)($enrollment["enrolled"] ?? 0);
if (empty($enrollment["success"]) || $jobs !== $enrolled || ($health["state"] ?? "") !== "ok") {
    fwrite(STDERR, "Mass Notify backup enrollment verification failed.\n");
    exit(3);
}
if ($jobs === 0) {
    echo "Native FreePBX backup adapter verified; this PBX has no administrator-defined module backup jobs yet.\n";
} else {
    echo "Native FreePBX backup adapter verified and enrolled in {$enrolled} module backup job(s).\n";
}
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "Native FreePBX backup integration verification failed. See $LOG_FILE."
    exit 1
  fi
  ensure_required_asterisk_capabilities || {
    log "Required Asterisk paging capabilities disappeared after FreePBX reload."
    exit 1
  }
  verify_asterisk_module_startup_persistence || {
    log "Required Asterisk modules are not configured to survive an Asterisk restart."
    exit 1
  }
  audio_dialplan="$(asterisk -rx "dialplan show 1000@sls-alert-audio" 2>&1)"
  printf '%s\n' "$audio_dialplan"
  if ! printf '%s\n' "$audio_dialplan" | /usr/bin/python3 -I -c 'import sys; text=sys.stdin.read(); prefix="Page(${SLS_DIAL},b(sls-alert-autoanswer^s^1(${EXTEN}))A(${SLS_SAFE_SOUND})inq,"; sys.exit(0 if any(prefix+str(seconds)+")" in text for seconds in range(1,6)) else 1)'; then
    log "The SLS audio paging context is missing its multi-contact Page/ConfBridge auto-answer and audio handler."
    exit 1
  fi
  if printf '%s\n' "$audio_dialplan" | grep -Fq 'Dial(${SLS_DIAL}'; then
    log "The SLS audio paging context still uses first-answer Dial semantics instead of multi-contact Page semantics."
    exit 1
  fi
  if ! printf '%s\n' "$audio_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,origin)' \
    || printf '%s\n' "$audio_dialplan" | grep -Fq 'Set(SLS_DIAL=${PJSIP_DIAL_CONTACTS'; then
    log "The SLS audio context lacks frozen-contact admission or still resolves new contacts after admission."
    exit 1
  fi
  if printf '%s\n' "$audio_dialplan" | grep -Eq 'macro-autoanswer|b\(autoanswer\^'; then
    log "The SLS audio paging context still depends on a FreePBX internal auto-answer context."
    exit 1
  fi
  autoanswer_dialplan="$(asterisk -rx "dialplan show s@sls-alert-autoanswer" 2>&1)"
  outbound_playback_dialplan="$(asterisk -rx "dialplan show s@sls-outbound-playback" 2>&1)"
  if ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,playback)' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'GotoIf($["${SLS_PHONE_ALLOW}"!="1"]?done)' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'Playback(${SLS_SAFE_SOUND})' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'Read(SLS_ACK,${SLS_ACK_PROMPT},1,,1,${SLS_ACK_TIMEOUT})' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,ack)'; then
    log "External voice playback lacks its per-recipient answer or keypad-result handling. No external test call was made."
    exit 1
  fi
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,contact)' || {
    log "The SLS auto-answer context lacks its exact-contact admission guard."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'PJSIP_HEADER(add,Alert-Info)' || {
    log "The SLS PJSIP auto-answer header context is missing."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'PJSIP_HEADER(add,Call-Info)' || {
    log "The SLS PJSIP Call-Info auto-answer header is missing."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'Set(SLS_AUTOANSWER_CONTACT=${CHANNEL(contact)})' || {
    log "The SLS PJSIP auto-answer context is missing per-contact user-agent discovery."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'PJSIP_AOR(${SLS_AUTOANSWER_AOR},contact)' || {
    log "The SLS PJSIP auto-answer context is missing its extension/AOR contact fallback."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq '${SLS_AUTOANSWER_UA:0:7}"="yealink"]?Set(SLS_ALERT_INFO=Intercom)' || {
    log "The SLS PJSIP auto-answer context is missing the Yealink Intercom Alert-Info policy."
    exit 1
  }
  command -v runuser >/dev/null 2>&1 || {
    log "runuser is required to verify Asterisk call-file spool access."
    exit 1
  }
  for spool_dir in /var/spool/asterisk/tmp /var/spool/asterisk/outgoing /var/spool/asterisk/outgoing_done; do
    [ -d "$spool_dir" ] || {
      log "Required Asterisk call-file spool directory is missing: $spool_dir"
      exit 1
    }
    if ! runuser -u asterisk -- test -w "$spool_dir" \
      || ! runuser -u asterisk -- test -x "$spool_dir"; then
      log "The asterisk service account cannot search and write to $spool_dir."
      exit 1
    fi
  done
  if [ "$(stat -c '%d' /var/spool/asterisk/tmp)" != "$(stat -c '%d' /var/spool/asterisk/outgoing)" ] \
    || [ "$(stat -c '%d' /var/spool/asterisk/outgoing)" != "$(stat -c '%d' /var/spool/asterisk/outgoing_done)" ]; then
    log "Asterisk's temporary, outgoing, and test-result call-file directories are on different filesystems."
    exit 1
  fi
  expected_sound_target="$DATA_DIR/sounds"
  for sound_link in \
    /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin \
    /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin; do
    if [ ! -L "$sound_link" ] || [ "$(readlink -f "$sound_link" 2>/dev/null || true)" != "$expected_sound_target" ]; then
      log "$sound_link does not resolve to the protected SLS sound directory."
      exit 1
    fi
  done
  for sound_file in \
    "$DATA_DIR/sounds/tones/opening_Paging_Tone_Opening.wav" \
    "$DATA_DIR/sounds/tones/closing_Paging_Tone_Closing.wav" \
    "$DATA_DIR/sounds/tones/opening_NWS_alert.wav" \
    "$DATA_DIR/sounds/tones/opening_Lightning_alert.wav"; do
    [ -r "$sound_file" ] || {
      log "Required paging sound is missing or unreadable: $sound_file"
      exit 1
    }
    sound_rate="$(soxi -r "$sound_file" 2>/dev/null || true)"
    sound_channels="$(soxi -c "$sound_file" 2>/dev/null || true)"
    sound_bits="$(soxi -b "$sound_file" 2>/dev/null || true)"
    if [ "$sound_rate" != "8000" ] || [ "$sound_channels" != "1" ] || [ "$sound_bits" != "16" ]; then
      log "Paging sound must be 8 kHz, mono, 16-bit PCM: $sound_file"
      exit 1
    fi
  done
  for writable_dir in "$DATA_DIR/sounds/tts" "$DATA_DIR/sounds/tones" /var/www/html/sls_mass_notify; do
    if ! runuser -u asterisk -- test -w "$writable_dir" \
      || ! runuser -u asterisk -- test -x "$writable_dir"; then
      log "The asterisk service account cannot search and write to $writable_dir."
      exit 1
    fi
  done
  for recording_file in \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Opening.wav \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Closing.wav \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_NWS_Alert.wav \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Lightning_Alert.wav; do
    [ -r "$recording_file" ] || {
      log "Bundled System Recording is missing: $recording_file"
      exit 1
    }
    [ "$(soxi -r "$recording_file" 2>/dev/null || true)" = "8000" ] \
      && [ "$(soxi -c "$recording_file" 2>/dev/null || true)" = "1" ] \
      && [ "$(soxi -b "$recording_file" 2>/dev/null || true)" = "16" ] || {
        log "Bundled System Recording has an invalid Asterisk WAV format: $recording_file"
        exit 1
      }
  done
  php -r '
require "/etc/freepbx.conf";
$required = [
    "custom/SLS_Mass_Notify_Paging_Tone_Opening",
    "custom/SLS_Mass_Notify_Paging_Tone_Closing",
    "custom/SLS_Mass_Notify_NWS_Alert",
    "custom/SLS_Mass_Notify_Lightning_Alert",
];
$stmt = \FreePBX::Database()->prepare("SELECT COUNT(*) FROM recordings WHERE filename = ?");
foreach ($required as $filename) {
    $stmt->execute([$filename]);
    if ((int)$stmt->fetchColumn() < 1) {
        fwrite(STDERR, "Missing System Recordings entry: " . $filename . "\n");
        exit(1);
    }
}
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log "Bundled sounds were not registered with FreePBX System Recordings. See $LOG_FILE."
    exit 1
  }
  asterisk_file_formats="$(asterisk -rx "core show file formats" 2>&1)"
  printf '%s\n' "$asterisk_file_formats" | grep -Eq '(^|[[:space:]])wav([[:space:]]|$)' || {
    log "Asterisk WAV file-format support is unavailable."
    exit 1
  }
  [ -f /etc/apache2/conf-available/sls-mass-notify.conf ] \
    && [ -e /etc/apache2/conf-enabled/sls-mass-notify.conf ] \
    && grep -Fq '<Directory /var/www/html/api/sipnotify>' /etc/apache2/conf-available/sls-mass-notify.conf \
    && grep -Fq '<Directory /var/www/html/api/sls-mass-notify>' /etc/apache2/conf-available/sls-mass-notify.conf \
    && grep -Fq '<Directory /var/www/html/sls_mass_notify>' /etc/apache2/conf-available/sls-mass-notify.conf || {
      log "The SLS Apache integration is missing or is not enabled."
      exit 1
    }
  notify_module_ready=0
  for _attempt in $(seq 1 20); do
    if asterisk_module_loaded res_pjsip_notify.so; then
      notify_module_ready=1
      break
    fi
    asterisk -rx "module load res_pjsip_notify.so" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    sleep 1
  done
  [ "$notify_module_ready" -eq 1 ] || {
    log "Asterisk res_pjsip_notify.so is not loaded; SIP NOTIFY cannot work."
    exit 1
  }
  grep -q "SLS Mass Notifications SIP NOTIFY Templates" /etc/asterisk/sip_notify_custom.conf || {
    log "Managed SIP NOTIFY templates were not installed in /etc/asterisk/sip_notify_custom.conf."
    exit 1
  }
  if [ ! -x /usr/local/bin/piper ]; then
    log "Piper wrapper was not created at /usr/local/bin/piper."
    exit 1
  fi
  /usr/local/bin/piper -h >/dev/null
  if [ -x /usr/local/bin/sls_mass_notify/piper/venv/bin/piper ]; then
    /usr/local/bin/sls_mass_notify/piper/venv/bin/piper -h >/dev/null
  elif [ -x /usr/local/bin/sls_mass_notify/piper/venv/bin/python ]; then
    /usr/local/bin/sls_mass_notify/piper/venv/bin/python -m piper -h >/dev/null
  else
    log "Piper venv runtime is missing or not executable."
    exit 1
  fi
  [ -x "$DATA_DIR/piper/venv/bin/piper" ] || {
    log "Piper compatibility executable is missing at $DATA_DIR/piper/venv/bin/piper."
    exit 1
  }
  runtime_python_files=(
    /usr/local/bin/sls_mass_notify/sls_notify.py
    /usr/local/bin/sls_mass_notify/sls_config.py
    /usr/local/bin/sls_mass_notify/sls_config_crypto.py
    /usr/local/bin/sls_mass_notify/sls_console.py
    /usr/local/bin/sls_mass_notify/sls_cluster_guard.py
    /usr/local/bin/sls_mass_notify/sls_branded_email.py
    /usr/local/bin/sls_mass_notify/sls_announcement_email.py
    /usr/local/bin/sls_mass_notify/sls_branded_discord.py
    /usr/local/bin/sls_mass_notify/sls_notification_destinations.py
    /usr/local/bin/sls_mass_notify/sls_system_notifications.py
    /usr/local/bin/sls_mass_notify/sls_nws_status.py
    /usr/local/bin/sls_mass_notify/sls_nws_delivery_claims.py
    /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py
    /usr/local/bin/sls_mass_notify/sls_weather_queue.py
    /usr/local/bin/sls_mass_notify/sls_audio_queue.py
    /usr/local/bin/sls_mass_notify/sls_audio_state.py
    /usr/local/bin/sls_mass_notify/sls_storage_maintenance.py
    /usr/local/bin/sls_mass_notify/sls_audit_separation.py
    /usr/local/bin/sls_mass_notify/sls_operational_backup.py
    /usr/local/bin/sls_mass_notify/sls_external_monitor.py
    /usr/local/bin/sls_mass_notify/sls_piper_dependencies.py
    /usr/local/bin/sls_mass_notify/sls_piper_environment.py
    /usr/local/bin/sls_mass_notify/sls_update_policy.py
    /usr/local/bin/sls_mass_notify/sls_module_trust.py
    /usr/local/bin/sls_mass_notify/sls_privileged_install.py
    /usr/local/bin/sls_mass_notify/sls_installer_recovery.py
    /usr/local/bin/sls_mass_notify/sls_upgrade_trust.py
    /usr/local/bin/sls_mass_notify/sls_install_idle.py
    /usr/local/bin/sls_mass_notify/sls_install_guard.py
    /usr/local/bin/sls_mass_notify/sls_legacy_permissions.py
    /usr/local/bin/sls_mass_notify/sls_mail_admission.py
    /usr/local/bin/sls_mass_notify/sls_cron_runner.py
    /usr/local/bin/sls_mass_notify/sls_resource_capacity.py
    /usr/local/bin/sls_mass_notify/sls_bounded_stream.py
    /usr/local/bin/sls_mass_notify/sls_phone_admission.py
    /usr/local/bin/sls_mass_notify/sls_voicemail.py
    /usr/local/bin/sls_mass_notify/sls_phone_events.py
    /usr/local/bin/sls_mass_notify/sls_emergency_observer.py
    /usr/local/bin/sls_mass_notify/sls_trigger_actions.py
    /usr/local/bin/sls_mass_notify/sls_weather_channels.py
    /usr/local/bin/sls_mass_notify/sls_lightning_providers.py
    /usr/local/bin/sls_mass_notify/sls_phone_outcomes.py
    /usr/local/bin/sls_mass_notify/sls_outbound_routes.py
    /usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py
    /usr/local/bin/sls_mass_notify/sls_release_verify.py
    /usr/local/bin/sls_mass_notify/sls_release_trust.py
    /usr/local/bin/sls_mass_notify/sls_install_doctor.py
    /usr/local/bin/sls_mass_notify/sls_install_transaction.py
    /usr/local/bin/sls_mass_notify/sls_phone_inventory.py
    /usr/local/bin/sls_mass_notify/sls_speech_admission.py
  )
  for runtime_python in "${runtime_python_files[@]}"; do
    [ -x "$runtime_python" ] || {
      log "Required Python runtime is missing or not executable: $runtime_python"
      exit 1
    }
  done
  python3 -I - "${runtime_python_files[@]}" <<'PY'
import pathlib
import sys

for value in sys.argv[1:]:
    path = pathlib.Path(value)
    compile(path.read_text(encoding="utf-8"), str(path), "exec")
PY
  if /usr/local/bin/sls_mass_notify/sls_config.py "$CONFIG_FILE" >/dev/null; then
    :
  else
    config_status=$?
    INSTALL_ERROR_CATEGORY="protected_config_validation_failed"
    log "The protected central configuration failed validation after runtime integration."
    exit "$config_status"
  fi
  [ ! -e "$DATA_DIR/mass-notifications.conf" ] || {
    log "Obsolete executable shell configuration still exists at $DATA_DIR/mass-notifications.conf."
    exit 1
  }
  [ ! -e /usr/local/bin/sls_mass_notify/config.ini ] || {
    log "Obsolete duplicate Python configuration still exists."
    exit 1
  }
  media_probe="/var/www/html/sls_mass_notify/installer-render-check-$$.png"
  media_fetch="$(mktemp "$INSTALL_TEMP_ROOT/sls-mass-notify-render-fetch.XXXXXX")"
  rm -f "$media_probe"
  if ! runuser -u asterisk -- convert -size 480x272 xc:'#991b1b' -font DejaVu-Sans-Bold \
    -fill white -gravity center -pointsize 24 -annotate +0+0 'SLS render test' \
    -colorspace sRGB -depth 8 -interlace none -strip "PNG24:$media_probe"; then
    rm -f "$media_probe" "$media_fetch"
    log "The asterisk service account could not render an alert image in the public media directory."
    exit 1
  fi
  /usr/sbin/apache2ctl configtest >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    rm -f "$media_probe" "$media_fetch"
    log "Apache configuration validation failed after integration was installed. See $LOG_FILE."
    exit 1
  }
  verify_media_access || { rm -f "$media_probe" "$media_fetch"; exit 1; }
  media_code="$(local_web_probe "/sls_mass_notify/$(basename "$media_probe")" "$media_fetch" '^(200|403)$' || true)"
  if [ "$media_code" = "200" ] && cmp -s "$media_probe" "$media_fetch"; then
    :
  elif [ "$media_code" = "403" ] && /usr/bin/python3 -I - "$media_fetch" /usr/local/bin/sls_mass_notify/sls_config_crypto.py <<'PY'
import json
from pathlib import Path
import sys
body = Path(sys.argv[1]).read_bytes()
if len(body) > 1024:
    raise SystemExit(1)
import types
sys.dont_write_bytecode = True
path = Path(sys.argv[2])
module = types.ModuleType('sls_config_crypto'); module.__file__ = str(path)
exec(compile(path.read_bytes(), str(path), 'exec'), module.__dict__)
config = module.read_config('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')
raise SystemExit(0 if config.get('media_access', {}).get('network_restricted') is True
                 and json.loads(body).get('error') == 'media_network_denied' else 1)
PY
  then
    log 'Generated-media policy correctly blocks this local probe. Image bytes are validated locally; test image retrieval from an allowed device network.'
  else
    rm -f "$media_probe" "$media_fetch"
    log "Public phone-image delivery check failed with HTTP $media_code."
    exit 1
  fi
  media_identity="$(identify -format '%w %h %[bit-depth] %[colorspace] %[interlace]' "$media_probe" 2>/dev/null || true)"
  rm -f "$media_probe" "$media_fetch"
  case "$media_identity" in
    "480 272 8 sRGB None"|"480 272 8 RGB None") ;;
    *)
      log "Rendered phone image does not meet the 480x272, 8-bit, non-interlaced requirement: $media_identity"
      exit 1
      ;;
  esac
  log "Verifying Asterisk PJSIP contact inventory and SLS AMI authentication."
  verify_pjsip_contact_inventory || exit 1
  if ! verify_ami_with_repair; then
    exit 1
  fi
  verify_phone_collector || exit 1
  notify_capabilities="$(mktemp "$INSTALL_TEMP_ROOT/sls-mass-notify-notify-capabilities.XXXXXX")"
  if ! /usr/bin/timeout 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py --notify-capabilities-json >"$notify_capabilities"; then
    rm -f "$notify_capabilities"
    log "SLS could not inspect Asterisk SIP NOTIFY routing capabilities."
    exit 1
  fi
  notify_routing_mode="$(python3 -I - "$notify_capabilities" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    capabilities = json.load(handle)
if not isinstance(capabilities, dict) or capabilities.get("endpoint_target") is not True:
    raise SystemExit("Asterisk does not expose portable endpoint-targeted PJSIPNotify")
mode = capabilities.get("routing_mode")
if mode not in {"endpoint_fanout", "contact_uri"}:
    raise SystemExit("Asterisk returned an invalid SIP NOTIFY routing mode")
print(mode)
PY
)" || {
    cat "$notify_capabilities" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    rm -f "$notify_capabilities"
    log "Asterisk does not expose the required endpoint-targeted PJSIPNotify capability."
    exit 1
  }
  if [ "$notify_routing_mode" = "contact_uri" ]; then
    log "SIP NOTIFY routing verified: endpoint fan-out is primary; contact URI routing is available for mixed-format registrations."
  else
    log "SIP NOTIFY routing verified: portable endpoint fan-out will be used because no usable default outbound endpoint is configured."
  fi
  rm -f "$notify_capabilities"
  # Always exercise the same AMI discovery path used by the Dashboard. Do not
  # infer whether it should run from the presentation-dependent summary line of
  # `pjsip show contacts`; community Asterisk builds format that output
  # differently, and an empty PBX is a valid installation state.
  endpoint_inventory="$(mktemp "$INSTALL_TEMP_ROOT/sls-mass-notify-endpoints.XXXXXX")"
  endpoint_inventory_err="$(mktemp "$INSTALL_TEMP_ROOT/sls-mass-notify-endpoints-error.XXXXXX")"
  if ! /usr/bin/timeout 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py --list-endpoints-json \
    >"$endpoint_inventory" 2>"$endpoint_inventory_err"; then
    cat "$endpoint_inventory_err" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    rm -f "$endpoint_inventory" "$endpoint_inventory_err"
    log "SLS AMI PJSIP contact discovery failed. Confirm the loopback manager user has system-event read permission; see $LOG_FILE."
    exit 1
  fi
  endpoint_records=""
  if ! endpoint_records="$(endpoint_inventory_records "$endpoint_inventory" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}")"; then
    cat "$endpoint_inventory" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    rm -f "$endpoint_inventory" "$endpoint_inventory_err"
    log "SLS AMI PJSIP contact discovery returned invalid data; see $LOG_FILE."
    exit 1
  fi

  registered_endpoint_count=0
  yealink_endpoint_list=""
  while IFS=$'\t' read -r extension phone_format contact_count unknown_format mixed_format formats_csv user_agent; do
    [ -n "$extension" ] || continue
    registered_endpoint_count=$((registered_endpoint_count + 1))
    if [ "$unknown_format" = "1" ]; then
      log "Warning: registered endpoint $extension has an unknown phone format (user agent: ${user_agent:-not reported})."
      log "A safe generic SIP NOTIFY fallback will be used; add a Phone Format Override after confirming the device family."
    fi
    case ",${formats_csv}," in
      *,yealink,*|*,yealink_text,*|*,yealink_image,*)
        yealink_endpoint_list="${yealink_endpoint_list}${yealink_endpoint_list:+,}${extension}"
        ;;
    esac
    if [ "$mixed_format" = "1" ]; then
      if [ "$notify_routing_mode" = "contact_uri" ]; then
        log "Warning: extension $extension has mixed registered phone formats ($formats_csv). Each resolved contact URI will receive its matching vendor payload."
      else
        log "Warning: extension $extension has mixed registered phone formats ($formats_csv), but contact-URI routing is unavailable. Runtime delivery will use one safe generic XML payload through portable endpoint fan-out."
      fi
    fi
    if ! verify_registered_endpoint_route "$extension"; then
      rm -f "$endpoint_inventory" "$endpoint_inventory_err"
      log "Registered endpoint route validation failed before the installation was committed."
      exit 1
    fi
    log "Registered endpoint $extension discovery verified through AMI: format=$phone_format contacts=$contact_count."
  done <<<"$endpoint_records"

  if [ -n "$yealink_endpoint_list" ]; then
    log "Warning: Yealink registration(s) detected on extension(s) $yealink_endpoint_list. Auto-answer requires the phone's Intercom Allow policy; XML SIP NOTIFY display requires push_xml.sip_notify = 1 (Features > Remote Control > SIP Notify)."
    log "The installer does not modify handset provisioning, firmware, or SIP peers. Confirm these settings on each Yealink phone or in its authorized provisioning template."
  fi

  if [ "$registered_endpoint_count" -eq 0 ]; then
    log "No registered numeric PJSIP contacts are present; AMI discovery and empty-PBX handling were verified."
  else
    log "AMI discovery and PJSIP paging routes verified for $registered_endpoint_count registered endpoint(s)."
  fi
  rm -f "$endpoint_inventory" "$endpoint_inventory_err"
  php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php >/dev/null
  verify_local_api_route /api/sipnotify/desktop '^(401|429)$' '401/429' 'Desktop notification API smoke test' || exit 1
  verify_local_api_route /api/sipnotify/desktop/stream '^(401|429)$' '401/429' 'Desktop live-stream API without credentials' || exit 1
  verify_control_api_authentication || exit 1
  # Use the canonical directory URL. Apache otherwise returns its expected
  # DirectorySlash 301 before the protected API front controller runs.
  verify_local_api_route /api/sls-mass-notify/ '^(401|403|405|429)$' '401/403/405/429' 'Control API route smoke test' || exit 1
  verify_local_api_route /api/sls-mass-notify/sms-callback.php '^405$' '405' 'SMS callback route without a provider submission' || exit 1
  verify_desktop_sse_handshake || exit 1
  [ "$(stat -c '%U:%G' /usr/local/bin/sls_mass_notify)" = "root:root" ] || {
    log "Executable runtime is not owned by root:root."
    exit 1
  }
  [ "$(stat -c '%U:%G' /usr/local/bin/sls_mass_notify/piper/venv/bin/piper)" = "root:root" ] || {
    log "Piper executable is not owned by root:root."
    exit 1
  }
  systemctl is-active --quiet cron || {
    log "The cron scheduler stopped during installation."
    exit 1
  }
  root_cron="$(crontab -l 2>/dev/null || true)"
  asterisk_cron="$(crontab -u asterisk -l 2>/dev/null || true)"
  verify_install_cron "$root_cron" "$asterisk_cron" || exit 1
  if ! runuser -u asterisk -- /usr/bin/timeout 20 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php --self-test >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "Scheduled-announcement worker failed its Asterisk-account self-test."
    exit 1
  fi
  scheduler_probe="$(mktemp -d "$INSTALL_TEMP_ROOT/sls-mass-notify-scheduler-check.XXXXXX")"
  chown asterisk:asterisk "$scheduler_probe"
  chmod 0750 "$scheduler_probe"
  printf '%s\n' '{"enabled":"0","xweather":{"enabled":"0"}}' >"$scheduler_probe/disabled.config"
  chown asterisk:asterisk "$scheduler_probe/disabled.config"
  chmod 0640 "$scheduler_probe/disabled.config"
  if ! runuser -u asterisk -- /usr/bin/env \
    CONFIG_FILE="$scheduler_probe/disabled.config" \
    DATA_DIR="$scheduler_probe" \
    LOG="$scheduler_probe/weather.log" \
    RUNTIME_DIR="/usr/local/bin/sls_mass_notify" \
    /usr/bin/timeout 15 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh; then
    rm -rf "$scheduler_probe"
    log "Weather scheduler wrapper failed its disabled-config execution check."
    exit 1
  fi
  rm -rf "$scheduler_probe"
  if [ ! -x /usr/sbin/sendmail ]; then
    log "Warning: /usr/sbin/sendmail is unavailable; email delivery will remain disabled until a local MTA is installed."
  elif [ ! -x /usr/sbin/postqueue ]; then
    log "Warning: SLS email requires the local Postfix postqueue command to check backlog before submission. No mail transport was changed; configure Postfix before using SLS email."
  fi
  ls -lh /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices
}

inspect_installation() {
  # Inspection uses only existing utilities and private evidence. It does not
  # bootstrap PHP/FreePBX, repair packages, acquire/create worker locks or enroll
  # trust pointers. All active and pending settings remain untouched.
  local utility report
  for utility in /usr/bin/python3 /usr/bin/openssl /usr/bin/tar /usr/bin/php /usr/bin/sha256sum; do
    [ -x "$utility" ] || { printf 'Inspection needs the existing utility %s; it will not install packages.\n' "$utility" >&2; return 1; }
  done
  [ -x /usr/bin/curl ] || [ -x /usr/bin/wget ] || { printf 'Inspection needs curl or wget for signed release evidence.\n' >&2; return 1; }
  [ -f /etc/freepbx.conf ] && [ -f /var/www/html/admin/modules/framework/module.xml ] || { printf 'FreePBX is not present at the supported paths.\n' >&2; return 1; }
  if [ -e "$CONFIG_FILE" ] || [ -L "$CONFIG_FILE" ]; then
    report="$(bootstrap_resource_capacity --check --mode prepare --config "$CONFIG_FILE")" || { printf '%s\n' "$report"; return 1; }
  else
    report="$(bootstrap_resource_capacity --check --mode prepare --desktop-limit 25 --auto-phone-limit)" || { printf '%s\n' "$report"; return 1; }
  fi
  select_install_temp_root "$report" || return 1
  LOG_FILE="$(mktemp "$INSTALL_TEMP_ROOT/sls-mass-notify-inspection.XXXXXXXX.log")" || return 1
  chmod 0600 "$LOG_FILE" || return 1
  INSTALL_LOG_FD=""
  open_root_owned_file INSTALL_LOG_FD "$LOG_FILE" log || return 1
  INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$INSTALL_LOG_FD"
  printf '%s\n' "$report" >>"$INSTALL_LOG_OUTPUT"
  download_tgz && verify_tgz && stage_module_directory && prepare_installer_bootstrap && prepare_authenticated_installer || {
    printf 'Inspection failed. Evidence and diagnostics: %s\n' "$LOG_FILE" >&2; return 1;
  }
  printf 'Inspection passed. Evidence: %s; log: %s\n' "$UPGRADE_TRUST_DIR" "$LOG_FILE"
}

main() {
  cd /tmp
  if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    printf 'Run this installer as root.\n' >&2
    exit 1
  fi
  if [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" = 1 ]; then
    inspect_installation
    return $?
  fi
  ensure_installer_log_prerequisites || exit 1
  if [ -n "$REQUESTED_RECOVERY" ]; then
    recover_interrupted_installation "$REQUESTED_RECOVERY"
    return $?
  fi
  check_interrupted_installation || exit $?
  preflight_profile_identity || exit $?
  for coordination_utility in flock timeout runuser; do
    command -v "$coordination_utility" >/dev/null || { printf 'Installation coordination requires %s before bootstrap. Install coreutils/util-linux and retry; no PBX state was changed.\n' "$coordination_utility" >&2; exit 2; }
  done
  # Coordinate before appending the shared transaction log or starting repairs.
  acquire_update_coordination || exit 1
  acquire_maintenance_coordination || exit 1
  INSTALL_LOG_FD=""
  open_root_owned_file INSTALL_LOG_FD "$LOG_FILE" log || {
    printf 'The installer log could not be opened safely: %s. Check for a symlink, hardlink, special file, or unexpected owner. No module files were changed.\n' "$LOG_FILE" >&2
    exit 1
  }
  # Use the held descriptor for every write, including after fwconsole chown.
  # LOG_FILE remains the human-readable path for diagnostics and support.
  INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$INSTALL_LOG_FD"
  printf '\nSLS Mass Notify installation started: %s\n' "$(date --iso-8601=seconds)" >>"$INSTALL_LOG_OUTPUT"
  trap guard_config_on_exit EXIT
  trap 'handle_install_signal TERM 143' TERM
  trap 'handle_install_signal INT 130' INT
  trap 'handle_install_signal HUP 129' HUP
  set_install_stage "hardware admission" "Provide the combined PBX/SLS CPU, RAM, persistent storage, and free-space requirements documented in README before retrying installation."
  preflight_hardware_requirements || exit $?
  set_install_stage "platform validation" "Confirm this is a healthy FreePBX 17 host with working database, Asterisk, fwconsole, package repositories, and local AMI access, then rerun the installer."
  require_freepbx
  preflight_platform check
  preflight_python
  detect_served_php_profiles
  set_install_stage "release download and validation" "Confirm the release URL and checksum, restore GitHub or network access if needed, and rerun the installer."
  download_tgz
  verify_tgz
  stage_module_directory
  prepare_installer_bootstrap
  bootstrap_missing_dashboard
  set_install_stage "publisher and stock approval" "Review the upgrade evidence directory printed in the log. Standard stock modules bootstrap from signed upstream packages; local differences require independently reviewed inventories. No maintenance changes occur before approval."
  prepare_authenticated_installer
  # Persist authenticated recovery evidence before even the bounded native
  # chown exclusion or legacy metadata repair can change protected state.
  begin_install_transaction
  set_install_stage "FreePBX ownership protection" "Review the FreePBX chown configuration conflict named in the log. Existing unrelated ownership rules are preserved."
  protect_freepbx_chown_permissions
  INSTALL_ERROR_CATEGORY="legacy_permission_recovery_failed"
  set_install_stage "legacy configuration and runtime permissions" "Review the exact filename, ownership, mode or validation error in the log. Valid central configuration and a missing settings mutex are recovered without replacing settings or existing lock inodes. Unknown owners, links and damaged data require review."
  repair_authenticated_legacy_permissions
  INSTALL_ERROR_CATEGORY="install_command_failed"
  set_install_stage "authenticated prerequisite validation" "Review the specific prerequisite in the log. Previous executable files and approved PBX integration must still match their authenticated inventories."
  preflight_authenticated_prerequisites
  INSTALL_ERROR_CATEGORY="previous_collector_recovery_failed"
  set_install_stage "previous phone collector recovery" "Review the collector journal and approved service state. The installer verifies a fresh authenticated heartbeat before pausing notification workers; it does not reset phone history."
  restore_previous_phone_collector
  INSTALL_ERROR_CATEGORY="worker_coordination_failed"
  set_install_stage "notification worker coordination" "Let active SLS work finish, then retry. Review the named worker mutex if ownership, mode, contents or identity were rejected."
  acquire_worker_coordination
  INSTALL_ERROR_CATEGORY="protected_config_validation_failed"
  set_install_stage "configuration coordination" "Close active configuration editors and retry. The settings mutex must remain an empty, private regular file with a stable inode; review the specific failure in the log."
  acquire_settings_coordination
  snapshot_config
  snapshot_pending_config
  validate_preserved_config_prerequisites
  INSTALL_ERROR_CATEGORY="install_command_failed"
  verify_staged_hardware_requirements
  retain_configuration_snapshots
  persist_install_transaction prepared
  commit_authenticated_installer
  set_install_stage "dependency installation" "Restore Debian package repository access and install the prerequisite named in the installer log, then rerun the installer. Approved system packages are retained after a module rollback."
  install_dependencies
  verify_served_php_configuration
  preflight_python
  initialize_configuration_keyring
  validate_staged_central_config
  ensure_freepbx_prerequisites
  preflight_platform
  set_install_stage "timezone validation" "Choose a valid IANA timezone listed by timedatectl or repair the host timezone configuration."
  configure_system_timezone
  persist_install_transaction mutating
  set_install_stage "protected runtime preparation" "Correct the specific protected-path or dependency error in the installer log; do not run FreePBX module hooks as root."
  STATIC_MUTATION_STARTED=1
  protected_install_phase prepare --apply
  protected_install_phase dependencies --apply
  set_install_stage "module activation" "Review the rejected unprivileged FreePBX integration step in the installer log, then correct that prerequisite before retrying."
  activate_staged_module
  persist_install_transaction mutating
  initialize_fresh_install_config
  ensure_local_signer
  if SLS_MASS_NOTIFY_DEFER_SIGNING=1 install_module_with_autoenable; then
    :
  else
    install_status=$?
    log "FreePBX module installation failed (exit $install_status). See $LOG_FILE."
    exit "$install_status"
  fi
  SLS_MASS_NOTIFY_MODULE="$MODULE" sync_module_version
  set_install_stage "runtime integration" "Correct the named integration prerequisite in the installer log; protected operations require the authenticated release and approved stock inventories."
  ensure_runtime_installed
  secure_central_config
  # Fresh installs need the collector running before FreePBX postReload
  # performs readiness checks. Install already wrote the loopback AMI user.
  sls_as_asterisk /usr/sbin/asterisk -rx "manager reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  protected_install_phase activate --apply
  SLS_MASS_NOTIFY_PRESERVE_PENDING=1 run_without_install_maintenance_lock fwconsole reload
  protected_install_phase verify
  sls_as_asterisk /usr/sbin/asterisk -rx "module reload res_pjsip_notify.so" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  sls_as_asterisk /usr/sbin/asterisk -rx "dialplan reload" || true
  set_install_stage "post-install verification" "Review the failed verification in /tmp/slsmassnotifyserver-install.log, correct that PBX-specific prerequisite, and rerun the installer or Repair Installation."
  verify_runtime_shell_syntax
  verify_announcement_worker_health
  verify_piper_voices
  verify_served_php_runtime
  verify_install
  verify_confirmed_system_timezone
  verify_config_unchanged
  verify_pending_config_unchanged
  verify_install_idle_history
  INSTALL_COMMITTED=1
  persist_install_transaction committed || { INSTALL_COMMITTED=0; exit 1; }
  clear_install_failure
  log "SLS Mass Notify install finished."
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
