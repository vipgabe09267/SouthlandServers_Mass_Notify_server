#!/usr/bin/env bash
set -euo pipefail

umask 027
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH
# Removal is local and never needs publisher download credentials.
unset GITHUB_TOKEN GH_TOKEN SLS_MASS_NOTIFY_GITHUB_TOKEN

MODULE="slsmassnotifyserver"
if [ -n "${SLS_MASS_NOTIFY_MODULE:-}" ] \
  && [ "${SLS_MASS_NOTIFY_MODULE}" != "$MODULE" ]; then
  printf '%s\n' \
    "SLS_MASS_NOTIFY_MODULE is fixed to the FreePBX raw module name '$MODULE'; refusing an alternate value." >&2
  exit 2
fi
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
SIGN_HOME="/root/.gnupg-sls-mass-notify"
PURGE_CONFIG="${SLS_MASS_NOTIFY_PURGE_CONFIG:-0}"
case "$PURGE_CONFIG" in
  0|1) ;;
  *)
    printf '%s\n' 'SLS_MASS_NOTIFY_PURGE_CONFIG must be 0 or 1.' >&2
    exit 2
    ;;
esac
SIGNING_FINGERPRINT=""
KEEP_SIGNING_TRUST=0
RECOVERY_SIGNER_DIR=""
RECOVERY_SIGNER=""
UNINSTALL_APPROVED_SOURCE=""
UNINSTALL_TRANSACTION_DIR=""
CONFIGURED_AMI_USERNAME=""
CONFIGURED_AMI_SECRET_HASH=""
RECOVERY_DECODER_DIR=""
RECOVERY_CONFIG_DECODER="/usr/local/bin/sls_mass_notify/sls_config_crypto.php"
RECOVERY_AMI_OWNERSHIP_HELPER=""
UNINSTALL_MAINTENANCE_LOCK_FD=""
STOCK_RESTORE_LOG_PATH="/tmp/slsmassnotifyserver-uninstall-stock-modules.log"
MODULE_UNINSTALL_LOG_PATH="/tmp/slsmassnotifyserver-uninstall-module.log"
STOCK_RESTORE_LOG="$STOCK_RESTORE_LOG_PATH"
MODULE_UNINSTALL_LOG="$MODULE_UNINSTALL_LOG_PATH"
STOCK_RESTORE_LOG_FD=""
MODULE_UNINSTALL_LOG_FD=""
MODULE_REGISTRY_PRESENT=0
PBX_RUNNING=0
UNINSTALL_WORKER_PID=""

run_as_asterisk() {
  local -a child_environment=(
    "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
    "LANG=C.UTF-8"
    "LC_ALL=C.UTF-8"
  )
  while [ "$#" -gt 0 ] && [[ "$1" == *=* ]]; do
    case "$1" in
      SLS_MODULE_NAME=*|SLS_CONFIG=*|SLS_UNINSTALL_CONFIG_DECODER=*|SLS_CONFIGURED_AMI_USERNAME=*|SLS_AMI_SECRET_HASH=*|SLS_AMI_OWNERSHIP_HELPER=*|SLS_DATA_DIR=*|SLS_VERIFY_MODULE=*)
        child_environment+=("$1")
        ;;
      *)
        printf '%s\n' "Refusing an unapproved runtime-account environment variable." >&2
        return 64
        ;;
    esac
    shift
  done
  [ "$#" -gt 0 ] || return 64
  /usr/bin/env -i "${child_environment[@]}" /usr/sbin/runuser -u asterisk -- "$@"
}
UNINSTALL_WORKER_PIPE=""
UNINSTALL_GUARD_REQUIRED=0

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

prepare_uninstall_logs() {
  open_root_owned_file MODULE_UNINSTALL_LOG_FD "$MODULE_UNINSTALL_LOG_PATH" log || {
    log "The uninstall log could not be opened safely: $MODULE_UNINSTALL_LOG_PATH. No uninstall changes were made."
    return 1
  }
  open_root_owned_file STOCK_RESTORE_LOG_FD "$STOCK_RESTORE_LOG_PATH" log || {
    log "The stock-module restore log could not be opened safely: $STOCK_RESTORE_LOG_PATH. No uninstall changes were made."
    return 1
  }
  # Keep writes attached to the checked inode even if FreePBX changes its
  # ownership or a log rotation replaces the original pathname during removal.
  MODULE_UNINSTALL_LOG="/proc/${BASHPID}/fd/$MODULE_UNINSTALL_LOG_FD"
  STOCK_RESTORE_LOG="/proc/${BASHPID}/fd/$STOCK_RESTORE_LOG_FD"
  : >"$MODULE_UNINSTALL_LOG"
  : >"$STOCK_RESTORE_LOG"
}

log() {
  printf '%s\n' "$*"
}

require_freepbx() {
  local required_command
  [ "${EUID:-$(id -u)}" -eq 0 ] || {
    log "Run the uninstaller as root."
    exit 1
  }
  for required_command in /usr/sbin/fwconsole /usr/sbin/asterisk /usr/sbin/runuser /usr/bin/php /usr/bin/python3 /usr/bin/gpg /usr/bin/crontab /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/bin/base64 /usr/bin/sha256sum /usr/bin/systemctl /usr/bin/su; do
    [ -x "$required_command" ] || {
      log "Required uninstall prerequisite is unavailable: $required_command. No uninstall changes were made."
      exit 1
    }
  done
  [ -d /var/www/html/admin/modules ] || {
    log "/var/www/html/admin/modules not found. This does not look like a FreePBX server."
    exit 1
  }
  [ -r /etc/freepbx.conf ] || {
    log "/etc/freepbx.conf is missing or unreadable."
    exit 1
  }
  run_as_asterisk /usr/bin/php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
\FreePBX::Database()->query("SELECT 1");
exit(0);
' >/dev/null || {
    log "FreePBX bootstrap or database access failed. No uninstall changes were made."
    exit 1
  }
}

acquire_maintenance_coordination() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
  local descriptor descriptor_path descriptor_target lock_parent

  [[ "$lock_file" = /* ]] && [ "$lock_file" != "/" ] || {
    log "Unsafe maintenance lock path."
    return 1
  }
  lock_parent="$(dirname -- "$lock_file")"
  [ -d "$lock_parent" ] && [ ! -L "$lock_parent" ] || {
    log "The maintenance lock parent must already exist as a real directory: $lock_parent"
    return 1
  }
  [ ! -L "$lock_file" ] || {
    log "Refusing a symbolic-link maintenance lock: $lock_file"
    return 1
  }

  # UI uninstalls are launched by the maintenance worker while it holds this
  # lock. Reuse that inherited lock; direct CLI uninstalls acquire their own.
  for descriptor_path in "/proc/${BASHPID}/fd/"*; do
    [ -e "$descriptor_path" ] || continue
    descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
    [ "$descriptor_target" = "$lock_file" ] || continue
    descriptor="${descriptor_path##*/}"
    if [[ "$descriptor" =~ ^[0-9]+$ ]] && flock -n "$descriptor"; then
      return 0
    fi
  done

  open_root_owned_file UNINSTALL_MAINTENANCE_LOCK_FD "$lock_file" || {
    log "The protected maintenance lock could not be opened safely."
    return 1
  }
  flock -w 120 "$UNINSTALL_MAINTENANCE_LOCK_FD" || {
    log "Another Mass Notify maintenance, update, repair, or uninstall operation is still running."
    return 1
  }
}

uninstall_recovery_workspace() {
  /usr/bin/python3 -I - "$1" <<'PYUNINSTALLSPACE'
import os
from pathlib import Path
import pwd
import re
import stat
import sys
import tempfile
prefix = sys.argv[1]
if not re.fullmatch(r'sls-uninstall-(?:signer|decoder|transaction)', prefix):
    raise SystemExit('Invalid uninstall recovery workspace.')
parent = Path('/var/lib/sls-mass-notify-uninstaller')
account = pwd.getpwnam('asterisk')
fd = os.open(parent.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
try:
    pm = os.fstat(fd)
    if pm.st_uid != 0 or pm.st_mode & 0o022:
        raise SystemExit('Uninstall recovery parent is unsafe.')
    try:
        os.mkdir(parent.name, 0o750, dir_fd=fd)
        created = True
    except FileExistsError:
        created = False
    child = os.open(parent.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
    try:
        if created:
            os.fchown(child, 0, account.pw_gid)
            os.fchmod(child, 0o750)
            os.fsync(fd)
        info = os.fstat(child)
        if info.st_uid != 0 or info.st_gid != account.pw_gid or stat.S_IMODE(info.st_mode) != 0o750:
            raise SystemExit('Existing uninstall recovery storage requires review; it was not adopted.')
    finally:
        os.close(child)
finally:
    os.close(fd)
print(tempfile.mkdtemp(prefix=prefix+'.', dir=parent))
PYUNINSTALLSPACE
}

record_uninstall_stage() {
  [ -n "$UNINSTALL_TRANSACTION_DIR" ] || return 1
  /usr/bin/python3 -I - "$UNINSTALL_TRANSACTION_DIR" "$1" "$UNINSTALL_APPROVED_SOURCE" "$RECOVERY_SIGNER_DIR" "$RECOVERY_DECODER_DIR" "$PURGE_CONFIG" <<'PYREMOVALSTATE'
import json, os, secrets, stat, sys, time
from pathlib import Path
path=Path(sys.argv[1]);phase=sys.argv[2]
if os.geteuid()!=0 or phase not in {'prepared','services','module','integration','runtime','verification','complete','failed'}:
    raise SystemExit('Invalid uninstall transaction stage.')
if path.resolve()!=path:
    raise SystemExit('Uninstall transaction path is not canonical.')
fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC)
temporary='.state-'+secrets.token_hex(12)
try:
    info=os.fstat(fd)
    if info.st_uid!=0 or stat.S_IMODE(info.st_mode)!=0o700:
        raise SystemExit('Uninstall transaction directory is unsafe.')
    before=None
    try:
        handle=os.open('transaction.json',os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=fd)
    except FileNotFoundError:
        state={'schema':1,'created_at':int(time.time()),'history':[]}
    else:
        try:
            before=os.fstat(handle)
            if not stat.S_ISREG(before.st_mode) or before.st_uid!=0 or before.st_nlink!=1 or before.st_mode&0o077 or before.st_size>16384:
                raise SystemExit('Uninstall transaction file is unsafe.')
            def unique_object(pairs):
                result={}
                for key,value in pairs:
                    if key in result: raise ValueError('duplicate JSON member')
                    result[key]=value
                return result
            state=json.loads(os.read(handle,16385), object_pairs_hook=unique_object,
                             parse_constant=lambda value: (_ for _ in ()).throw(ValueError('non-finite JSON number')))
        finally:os.close(handle)
        if not isinstance(state,dict) or state.get('schema')!=1 or not isinstance(state.get('history'),list) or len(state['history'])>=64:
            raise SystemExit('Uninstall transaction is damaged or full; preserve it for review.')
    state.update(phase=phase,updated_at=max(int(time.time()),state['created_at']),approved_source=sys.argv[3],
                 recovery_signer=sys.argv[4],recovery_decoder=sys.argv[5],configuration_snapshot='',purge=sys.argv[6]=='1')
    state['history'].append({'phase':phase,'at':state['updated_at']})
    body=(json.dumps(state,separators=(',',':'))+'\n').encode()
    if len(body)>16384:raise SystemExit('Uninstall transaction exceeds its bound.')
    output=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=fd)
    with os.fdopen(output,'wb') as target:target.write(body);target.flush();os.fsync(target.fileno())
    try:current=os.stat('transaction.json',dir_fd=fd,follow_symlinks=False)
    except FileNotFoundError:current=None
    if (before is None)!=(current is None) or (before is not None and (before.st_dev,before.st_ino)!=(current.st_dev,current.st_ino)):
        raise SystemExit('Uninstall transaction changed during its commit.')
    os.replace(temporary,'transaction.json',src_dir_fd=fd,dst_dir_fd=fd);os.fsync(fd)
finally:
    try:
        os.unlink(temporary,dir_fd=fd)
        os.fsync(fd)
    except FileNotFoundError:pass
    os.close(fd)
PYREMOVALSTATE
}

snapshot_local_signer() {
  local candidate=""
  local trust_helper="${SLS_MASS_NOTIFY_TRUST_HELPER_SOURCE:-/usr/local/bin/sls_mass_notify/sls_module_trust.py}"
  local -a candidates=()

  if [ -n "$UNINSTALL_APPROVED_SOURCE" ]; then
    RECOVERY_SIGNER_DIR="$(uninstall_recovery_workspace sls-uninstall-signer)" || return 1
    chmod 0700 "$RECOVERY_SIGNER_DIR" || return 1
    RECOVERY_DECODER_DIR="$(uninstall_recovery_workspace sls-uninstall-decoder)" || return 1
    chown root:asterisk "$RECOVERY_DECODER_DIR" && chmod 0750 "$RECOVERY_DECODER_DIR" || return 1
    /usr/bin/python3 -I - "$UNINSTALL_APPROVED_SOURCE/bin/sls_mass_notify/sls_module_trust.py" "$UNINSTALL_APPROVED_SOURCE" "$RECOVERY_SIGNER_DIR" "$RECOVERY_DECODER_DIR" <<'PYAPPROVEDSNAPSHOT' || return 1
from pathlib import Path
import os, pwd, re, sys, types
path, source, target, decoder = map(Path, sys.argv[1:])
module = types.ModuleType('sls_uninstall_snapshot_trust'); module.__file__ = str(path)
exec(compile(path.read_bytes(), str(path), 'exec'), module.__dict__)
data, generation = module.load(Path('/var/lib/sls-mass-notify-trust'), 'slsmassnotifyserver')
if source != generation / 'module' or data['source']['kind'] != 'publisher-ed25519':
    raise SystemExit('Uninstall source is not the approved publisher generation')
names = {'bin/sign_sls_mass_notify_local_sig.sh':'sign_sls_mass_notify_local_sig.sh',
         'bin/sls_mass_notify/sls_module_trust.py':'sls_module_trust.py',
         'bin/sls_mass_notify/sls_install_guard.py':'sls_install_guard.py',
         'bin/sls_mass_notify/sls_install_idle.py':'sls_install_idle.py',
         'bin/sls_mass_notify/sls_audio_state.py':'sls_audio_state.py',
         'bin/sls_mass_notify/sls_config_crypto.py':'sls_config_crypto.py',
         'PhoneEventService.php':'PhoneEventService.php'}
optional = 'bin/sls_mass_notify/sls_legacy_permissions.py'
if optional in data['files']: names[optional] = 'sls_legacy_permissions.py'
for name, filename in names.items():
    entry = data['files'].get(name)
    if not entry: raise SystemExit('Signed uninstall helper is missing: ' + name)
    body = module.read(source / name, 2 * 1024 * 1024, protected=True)
    if module.digest(body) != entry['sha256']:
        raise SystemExit('Signed uninstall helper changed: ' + name)
    fd = os.open(target / filename, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o700)
    with os.fdopen(fd, 'wb') as output:
        output.write(body); output.flush(); os.fsync(output.fileno())
service = (target / 'PhoneEventService.php').read_bytes()
templates = re.findall(rb"return <<<'UNIT'\n(.*?)\nUNIT;", service, re.S)
if len(templates) != 1 or not templates[0].startswith(b'# Managed by SLS Mass Notify Server\n[Unit]\n'):
    raise SystemExit('The signed collector unit template is unavailable or ambiguous')
fd = os.open(target / 'collector-unit.txt', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(fd, 'wb') as output:
    # PHP excludes the line break introducing its heredoc terminator.
    output.write(templates[0]); output.flush(); os.fsync(output.fileno())
approval = {'schema': 1, 'source': 'publisher-verified-previous-release', 'version': data['version'],
            'archive_sha256': data['source']['archive_sha256'],
            'files': {str(source / name): entry['sha256'] for name, entry in data['files'].items()}}
fd = os.open(target / 'uninstall-approval.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(fd, 'wb') as output:
    output.write(__import__('json').dumps(approval, separators=(',', ':')).encode()); output.flush(); os.fsync(output.fileno())
name = 'bin/sls_mass_notify/sls_config_crypto.php'
entry = data['files'].get(name)
if not entry: raise SystemExit('Signed configuration decoder is missing')
body = module.read(source / name, 2 * 1024 * 1024, protected=True)
if module.digest(body) != entry['sha256']: raise SystemExit('Signed configuration decoder changed')
fd = os.open(decoder / 'sls_config_crypto.php', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
with os.fdopen(fd, 'wb') as output:
    os.fchown(output.fileno(), 0, pwd.getpwnam('asterisk').pw_gid)
    output.write(body); output.flush(); os.fsync(output.fileno())
name = 'AmiUserOwnership.php'
entry = data['files'].get(name)
if not entry: raise SystemExit('The current local uninstaller requires its signed AMI ownership helper. Preserve the approved generation; no teardown was started.')
body = module.read(source / name, 2 * 1024 * 1024, protected=True)
if module.digest(body) != entry['sha256']: raise SystemExit('Signed AMI ownership helper changed')
fd = os.open(decoder / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
with os.fdopen(fd, 'wb') as output:
    os.fchown(output.fileno(), 0, pwd.getpwnam('asterisk').pw_gid)
    output.write(body); output.flush(); os.fsync(output.fileno())
PYAPPROVEDSNAPSHOT
    RECOVERY_SIGNER="$RECOVERY_SIGNER_DIR/sign_sls_mass_notify_local_sig.sh"
    RECOVERY_CONFIG_DECODER="$RECOVERY_DECODER_DIR/sls_config_crypto.php"
    RECOVERY_AMI_OWNERSHIP_HELPER="$RECOVERY_DECODER_DIR/AmiUserOwnership.php"
    bash -n "$RECOVERY_SIGNER" || return 1
    return 0
  fi

  if [ -n "${SLS_MASS_NOTIFY_SIGNER_SOURCE:-}" ]; then
    candidates+=("$SLS_MASS_NOTIFY_SIGNER_SOURCE")
  fi
  candidates+=(
    "/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
    )

  for candidate in "${candidates[@]}"; do
    [ -e "$candidate" ] || continue
    [ -f "$candidate" ] && [ ! -L "$candidate" ] || {
      log "Refusing an unsafe local signer while preparing uninstall: $candidate"
      return 1
    }
    # Snapshot only an inventory-aware signer. Older permissive signers must
    # never become a fallback authorization path after removing the module.
    grep -Fq 'publish_candidate_transactionally' "$candidate" \
      && grep -Fq 'acquire_signing_lock' "$candidate" \
      && grep -Fq 'approved_module_hashes' "$candidate" || continue
    [ "$(stat -c '%U:%G' "$candidate" 2>/dev/null || true)" = "root:root" ] \
      && [ "$(stat -c '%a' "$candidate" 2>/dev/null || true)" = "755" ] || {
      log "The transactional local signer has unsafe ownership or permissions: $candidate"
      return 1
    }
    bash -n "$candidate" || {
      log "The installed PBX-local signer is not valid Bash: $candidate"
      return 1
    }
    RECOVERY_SIGNER_DIR="$(uninstall_recovery_workspace sls-uninstall-signer)" || return 1
    chmod 0700 "$RECOVERY_SIGNER_DIR"
    RECOVERY_SIGNER="$RECOVERY_SIGNER_DIR/sign_sls_mass_notify_local_sig.sh"
    install -m 0700 -o root -g root "$candidate" "$RECOVERY_SIGNER" || return 1
    cmp -s "$candidate" "$RECOVERY_SIGNER" || {
      log "Unable to snapshot the PBX-local signer exactly."
      return 1
    }
    /usr/bin/python3 -I - "$trust_helper" "$RECOVERY_SIGNER_DIR/sls_module_trust.py" <<'PYTRUSTCOPY' || return 1
import os
from pathlib import Path
import stat
import sys
source, target = map(Path, sys.argv[1:])
for item in [source] + list(source.parents)[:-1]:
    info = item.lstat()
    if (stat.S_ISLNK(info.st_mode) or info.st_uid != 0
            or info.st_mode & 0o022 and not (item != source and info.st_mode & stat.S_ISVTX)):
        raise SystemExit("Protected module trust helper has an unsafe path")
fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
with os.fdopen(fd, "rb") as handle:
    info = os.fstat(handle.fileno())
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 256 * 1024:
        raise SystemExit("Protected module trust helper is unsafe")
    body = handle.read(256 * 1024 + 1)
    after = os.fstat(handle.fileno())
    if len(body) != info.st_size or len(body) > 256 * 1024 or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
        raise SystemExit("Protected module trust helper changed during snapshot")
fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o700)
with os.fdopen(fd, "wb") as handle:
    handle.write(body); handle.flush(); os.fsync(handle.fileno())
PYTRUSTCOPY
    return 0
  done
  return 0
}

cleanup_recovery_signer() {
  release_uninstall_workers
  if [ -n "$RECOVERY_SIGNER_DIR" ] && [ -d "$RECOVERY_SIGNER_DIR" ]; then
    find "$RECOVERY_SIGNER_DIR" -depth -delete 2>/dev/null || true
  fi
  RECOVERY_SIGNER_DIR=""
  RECOVERY_SIGNER=""
  if [ -n "$RECOVERY_DECODER_DIR" ] && [ -d "$RECOVERY_DECODER_DIR" ]; then
    find "$RECOVERY_DECODER_DIR" -depth -delete 2>/dev/null || true
  fi
  RECOVERY_DECODER_DIR=""
}

release_uninstall_workers() {
  if [ -n "${UNINSTALL_WORKER_PIPE:-}" ]; then
    # A rejected/dead child may have already closed its pipe. Contain SIGPIPE
    # in this subshell so EXIT cleanup still preserves the original result.
    ( trap '' PIPE; printf 'release\n' >&"$UNINSTALL_WORKER_PIPE" ) 2>/dev/null || true
    exec {UNINSTALL_WORKER_PIPE}>&-
    UNINSTALL_WORKER_PIPE=""
  fi
  if [ -n "${UNINSTALL_WORKER_PID:-}" ]; then
    wait "$UNINSTALL_WORKER_PID" 2>/dev/null || true
    UNINSTALL_WORKER_PID=""
  fi
}

assert_uninstall_workers() {
  [ "$UNINSTALL_GUARD_REQUIRED" != 1 ] || {
    [ -n "$UNINSTALL_WORKER_PID" ] && kill -0 "$UNINSTALL_WORKER_PID" 2>/dev/null || {
      log 'The protected uninstall worker guard exited. Teardown stopped; preserve the signed recovery evidence and retry when SLS workers are idle.'
      return 1
    }
  }
}

acquire_uninstall_workers() {
  local guard_input guard_output status helper="$RECOVERY_SIGNER_DIR/sls_install_guard.py"
  UNINSTALL_GUARD_REQUIRED=0
  if [ -e "$DATA_DIR" ] || [ -L "$DATA_DIR" ]; then
    # The optional narrow migration uses authenticated retained sources and
    # changes only recognized legacy modes; bytes and lock inodes are retained.
    if [ -f "$RECOVERY_SIGNER_DIR/sls_legacy_permissions.py" ]; then
      /usr/bin/python3 -I "$RECOVERY_SIGNER_DIR/sls_legacy_permissions.py" --data "$DATA_DIR" \
        --approval "$RECOVERY_SIGNER_DIR/uninstall-approval.json" repair || return 1
    fi
    UNINSTALL_GUARD_REQUIRED=1
    coproc SLS_UNINSTALL_GUARD {
      /usr/bin/python3 -I "$helper" --data "$DATA_DIR" --timeout 120 hold
    }
    UNINSTALL_WORKER_PID="${SLS_UNINSTALL_GUARD_PID:-}"
    guard_input="${SLS_UNINSTALL_GUARD[1]:-}"; guard_output="${SLS_UNINSTALL_GUARD[0]:-}"
    if [ -z "$UNINSTALL_WORKER_PID" ] || [ -z "$guard_input" ] || [ -z "$guard_output" ] \
      || ! exec {UNINSTALL_WORKER_PIPE}>&"$guard_input" \
      || ! IFS= read -r -t 125 status <&"$guard_output" || [ "$status" != ready ]; then
      release_uninstall_workers
      log 'Unable to drain SLS workers safely. Wait for announcements/pages to finish, stop external SLS supervisors and review the named unsafe lock before retrying. No teardown was started.'
      return 1
    fi
    assert_uninstall_workers || return 1
  else
    # A late explicit-purge retry has no locks to acquire. Require the already
    # removed module/registry and public runtime before allowing this state.
    inspect_module_registry || return 1
    [ "$MODULE_REGISTRY_PRESENT" != 1 ] || {
      log 'SLS data is missing while its module registry entry remains. Preserve the signed evidence and review this incomplete teardown; no removal was started.'
      return 1
    }
    local entry
    for entry in /var/www/html/admin/modules/slsmassnotifyserver /usr/local/bin/sls_mass_notify \
      /var/www/html/api/sipnotify /var/www/html/api/sls-mass-notify /var/www/html/mass-notify; do
      if [ -e "$entry" ] || [ -L "$entry" ]; then
        log 'SLS data is missing while delivery entry points remain. Preserve the signed evidence and review this incomplete teardown; no removal was started.'
        return 1
      fi
    done
  fi
}

inspect_uninstall_idle() (
  if [ -n "${UNINSTALL_WORKER_PIPE:-}" ]; then exec {UNINSTALL_WORKER_PIPE}>&-; fi
  /usr/bin/python3 -I - "$RECOVERY_SIGNER_DIR/sls_install_idle.py" "$DATA_DIR" "$PBX_RUNNING" <<'PYUNINSTALLIDLE'
from pathlib import Path
import importlib.util, inspect, os, sys
path, data, running = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
spec = importlib.util.spec_from_file_location('sls_uninstall_idle', path)
idle = importlib.util.module_from_spec(spec); spec.loader.exec_module(idle)
try:
    check = idle.IdleCheck(data)
    if running == '0':
        # Failed CLI access is not evidence that an existing Asterisk is idle.
        # Only a genuinely absent Asterisk process permits the empty inventory.
        proc = idle.directory(Path('/proc'))
        try:
            for name in idle.entries(proc, 32768, 3):
                if not name.isdigit(): continue
                try:
                    executable = os.readlink('/proc/' + name + '/exe')
                except (FileNotFoundError, ProcessLookupError): continue
                if Path(executable.removesuffix(' (deleted)')).name == 'asterisk':
                    raise idle.IdleError('Asterisk is running but its channel inventory is unavailable. Restore local control-socket access before removing SLS.')
        finally: os.close(proc)
        check.channels = lambda: ''
    check.no_processes(); check.no_pending(); check.no_playback()
    # Teardown does not require a healthy collector. Existing admission state
    # still must be valid and closed; unknown/live batches are not erased.
    # Prior signed helpers have no pending-voicemail keyword and already
    # perform the strict batch check without requiring collector health.
    parameters = inspect.signature(check.phone_history).parameters
    options = {'allow_pending_voicemail': True} if 'allow_pending_voicemail' in parameters else {}
    check.phone_history(False, False, **options)
except (idle.IdleError, OSError, ValueError, TypeError) as error:
    raise SystemExit('SLS uninstall safety check: ' + str(error) + '. No teardown was started; allow delivery to finish and preserve unknown state for review.')
PYUNINSTALLIDLE
)

close_inherited_maintenance_lock_fds() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
  local descriptor descriptor_path descriptor_target close_fd

  for descriptor_path in "/proc/${BASHPID}/fd/"*; do
    [ -e "$descriptor_path" ] || continue
    descriptor="${descriptor_path##*/}"
    [[ "$descriptor" =~ ^[0-9]+$ ]] && [ "$descriptor" -gt 2 ] || continue
    descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
    [ "$descriptor_target" = "$lock_file" ] || continue
    close_fd="$descriptor"
    exec {close_fd}>&-
  done
}

run_fwconsole() (
  # The parent uninstaller or maintenance worker retains the transaction lock;
  # descendants must not inherit it and accidentally extend its lifetime.
  close_inherited_maintenance_lock_fds
  if [ -n "${UNINSTALL_WORKER_PIPE:-}" ]; then exec {UNINSTALL_WORKER_PIPE}>&-; fi
  # PCRE JIT allocation can be denied by otherwise healthy unprivileged LXC
  # containers. Limit this compatibility override to the uninstall process.
  run_as_asterisk /usr/bin/php -d pcre.jit=0 /usr/sbin/fwconsole "$@"
)

module_registry_exists() {
  run_as_asterisk "SLS_MODULE_NAME=$MODULE" /usr/bin/php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$statement = \FreePBX::Database()->prepare("SELECT COUNT(*) FROM modules WHERE modulename = ?");
$statement->execute([(string)getenv("SLS_MODULE_NAME")]);
exit((int)$statement->fetchColumn() > 0 ? 0 : 1);
'
}

inspect_module_registry() {
  local result=0
  module_registry_exists || result=$?
  case "$result" in
    0) MODULE_REGISTRY_PRESENT=1 ;;
    1) MODULE_REGISTRY_PRESENT=0 ;;
    *) log "FreePBX module registry could not be read. Module files were not deleted; restore database access and retry."; return 1 ;;
  esac
}

remove_module_registration() {
  inspect_module_registry || return 1
  if [ "$MODULE_REGISTRY_PRESENT" = 1 ]; then
    if ! run_fwconsole ma uninstall "$MODULE" >"$MODULE_UNINSTALL_LOG" 2>&1; then
      log "FreePBX reported an error while uninstalling $MODULE; checking whether its registry row was removed."
    fi
  fi
  inspect_module_registry || return 1
  if [ "$MODULE_REGISTRY_PRESENT" = 1 ]; then
    run_fwconsole ma disable "$MODULE" >>"$MODULE_UNINSTALL_LOG" 2>&1 || true
    run_fwconsole ma delete "$MODULE" >>"$MODULE_UNINSTALL_LOG" 2>&1 || true
  fi
  inspect_module_registry || return 1
  if [ "$MODULE_REGISTRY_PRESENT" = 1 ]; then
    log "FreePBX left the $MODULE registry row behind; removing that single stale row before deleting module files."
    run_as_asterisk "SLS_MODULE_NAME=$MODULE" /usr/bin/php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$statement = \FreePBX::Database()->prepare("DELETE FROM modules WHERE modulename = ?");
$statement->execute([(string)getenv("SLS_MODULE_NAME")]);
exit(0);
'
  fi
  inspect_module_registry || return 1
  if [ "$MODULE_REGISTRY_PRESENT" = 1 ]; then
    log "Unable to remove the $MODULE registry row. Module files were not deleted."
    return 1
  fi
  rm -rf "/var/www/html/admin/modules/$MODULE"
}

capture_signing_fingerprint() {
  [ -d "$SIGN_HOME" ] || return 0
  SIGNING_FINGERPRINT="$(GNUPGHOME="$SIGN_HOME" gpg --batch --with-colons --list-keys 2>/dev/null | awk -F: '/^fpr:/ && !found {print $10; found=1}')" || {
    log "The existing signing keyring could not be inspected safely. Preserve it for review before uninstalling."
    return 1
  }
}

capture_pbx_running_state() {
  local response=""
  PBX_RUNNING=0
  if response="$(/usr/bin/timeout --signal=TERM 10 /usr/sbin/asterisk -rx 'core show uptime' 2>/dev/null)" \
    && grep -Fq 'System uptime:' <<<"$response"; then
    PBX_RUNNING=1
  fi
}

reload_removed_pbx_integration() {
  if [ "$PBX_RUNNING" != 1 ]; then
    log "SLS removal was verified. Asterisk is stopped or its control socket is unavailable; run fwconsole reload after PBX service is available. No PBX service was started."
    return 0
  fi
  if ! run_fwconsole reload >>"$MODULE_UNINSTALL_LOG" 2>&1; then
    log "SLS removal was verified, but the PBX integration reload failed. Review $MODULE_UNINSTALL_LOG_PATH, resolve the PBX reload error, and run fwconsole reload."
  fi
}

capture_configured_ami_username() {
  local ownership snapshot="$RECOVERY_DECODER_DIR/central-config.snapshot"
  /usr/bin/python3 -I - "$DATA_DIR/mass-notifications.config" "$snapshot" <<'PYAMISNAPSHOT' || return 1
import fcntl
import os
import pwd
import stat
import sys

source, target = sys.argv[1:]
account = pwd.getpwnam('asterisk')
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC

def parent(path, allowed_owners, allowed_groups):
    parts = path.split('/')[1:]
    if not path.startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
        raise RuntimeError('invalid configuration snapshot path')
    descriptor = os.open('/', directory_flags)
    try:
        for component in parts[:-1]:
            child = os.open(component, directory_flags, dir_fd=descriptor)
            metadata = os.fstat(child)
            sticky_root = metadata.st_uid == 0 and bool(metadata.st_mode & stat.S_ISVTX)
            if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in allowed_owners
                    or metadata.st_gid not in allowed_groups
                    or metadata.st_mode & 0o022 and not sticky_root):
                os.close(child)
                raise RuntimeError('unsafe configuration snapshot parent')
            os.close(descriptor)
            descriptor = child
        return descriptor, parts[-1]
    except BaseException:
        os.close(descriptor)
        raise

source_parent, source_name = parent(source, {0, account.pw_uid}, {0, account.pw_gid})
try:
    try:
        descriptor = os.open(source_name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC,
                             dir_fd=source_parent)
    except FileNotFoundError:
        raise SystemExit(0)
    try:
        before = os.fstat(descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or before.st_uid not in (0, account.pw_uid)
                or before.st_gid not in (0, account.pw_gid)
                or stat.S_IMODE(before.st_mode) not in (0o600, 0o640, 0o644)
                or before.st_size > 3 * 1024 * 1024):
            raise RuntimeError('central configuration is unsafe')
        fcntl.flock(descriptor, fcntl.LOCK_SH | fcntl.LOCK_NB)
        body = bytearray()
        while len(body) <= 3 * 1024 * 1024:
            chunk = os.read(descriptor, min(65536, 3 * 1024 * 1024 + 1 - len(body)))
            if not chunk:
                break
            body.extend(chunk)
        after = os.fstat(descriptor)
        named = os.stat(source_name, dir_fd=source_parent, follow_symlinks=False)
        identity = lambda row: (row.st_dev, row.st_ino, row.st_uid, row.st_gid,
                                row.st_mode, row.st_size, row.st_mtime_ns,
                                row.st_ctime_ns, row.st_nlink)
        if len(body) > 3 * 1024 * 1024 or len(body) != before.st_size \
                or identity(before) != identity(after) or identity(after) != identity(named):
            raise RuntimeError('central configuration changed while being snapshotted')
    finally:
        os.close(descriptor)
finally:
    os.close(source_parent)

target_parent, target_name = parent(target, {0}, {0, account.pw_gid})
try:
    output = os.open(target_name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                     0o600, dir_fd=target_parent)
    try:
        view = memoryview(body)
        while view:
            written = os.write(output, view)
            if written <= 0:
                raise OSError('short configuration snapshot write')
            view = view[written:]
        os.fchown(output, 0, account.pw_gid)
        os.fchmod(output, 0o640)
        os.fsync(output)
    finally:
        os.close(output)
    os.fsync(target_parent)
finally:
    os.close(target_parent)
PYAMISNAPSHOT
  if [ ! -f "$snapshot" ] || [ -L "$snapshot" ]; then
    CONFIGURED_AMI_USERNAME=""
    CONFIGURED_AMI_SECRET_HASH=""
    return 0
  fi
  ownership="$(run_as_asterisk "SLS_CONFIG=$snapshot" "SLS_UNINSTALL_CONFIG_DECODER=$RECOVERY_CONFIG_DECODER" /usr/bin/php -d pcre.jit=0 <<'PHPAMI'
<?php
$path = (string)getenv('SLS_CONFIG');
if ($path === '' || !file_exists($path)) { exit(0); }
$metadata = lstat($path);
if (is_link($path) || !is_file($path) || !is_array($metadata) || $metadata['nlink'] !== 1
    || ($metadata['mode'] & 0022) !== 0 || $metadata['size'] > 3 * 1024 * 1024) {
    fwrite(STDERR, "The central configuration is unsafe; preserve it for review before uninstalling.\n"); exit(1);
}
require_once (string)getenv('SLS_UNINSTALL_CONFIG_DECODER');
$body = file_get_contents($path);
clearstatcache(true, $path);
$after = lstat($path);
if (!is_string($body) || !is_array($after) || strlen($body) !== $metadata['size']
    || array_intersect_key($metadata, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime'])) !== array_intersect_key($after, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))) {
    fwrite(STDERR, "The central configuration changed while being read; no manager account was removed.\n"); exit(1);
}
$settings = \FreePBX\modules\SlsConfigCrypto::decode($body);
$username = (string)($settings['ami']['username'] ?? '');
if ($username !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $username)) {
    fwrite(STDERR, "The configured SLS AMI username is invalid; no manager account was removed.\n"); exit(1);
}
echo $username, "\t", hash('sha256', (string)($settings['ami']['password'] ?? ''));
PHPAMI
)" || return 1
  rm -f -- "$snapshot"
  IFS=$'\t' read -r CONFIGURED_AMI_USERNAME CONFIGURED_AMI_SECRET_HASH <<<"$ownership"
}

validate_retained_user_data() {
  /usr/bin/python3 -I - "$DATA_DIR" <<'PYRETAIN'
from pathlib import Path
import os, pwd, stat, sys
path = Path(sys.argv[1]); account = pwd.getpwnam('asterisk')
if not path.is_absolute() or '..' in path.parts:
    raise SystemExit('Invalid retained SLS data path')
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
fd = os.open('/', flags)
try:
    for part in path.parts[1:]:
        try: child = os.open(part, flags, dir_fd=fd)
        except FileNotFoundError: raise SystemExit(0)
        os.close(fd); fd = child
        info = os.fstat(fd)
        if info.st_uid not in (0, account.pw_uid) or info.st_gid not in (0, account.pw_gid) or info.st_mode & 0o022 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX):
            raise SystemExit('Retained SLS data has an unsafe owner or writable parent; no data was deleted')
finally: os.close(fd)
PYRETAIN
}

handle_uninstall_exit() {
  local status=$?
  trap - EXIT
  if [ -n "$UNINSTALL_TRANSACTION_DIR" ] && [ "$status" != 0 ]; then
    record_uninstall_stage failed || log "Uninstall transaction could not record failure; preserve $UNINSTALL_TRANSACTION_DIR."
  fi
  if [ "$status" = 0 ]; then
    cleanup_recovery_signer
  else
    log "Uninstall transaction retained: ${UNINSTALL_TRANSACTION_DIR:-not created}."
    log "Uninstall recovery evidence retained: ${RECOVERY_SIGNER_DIR:-not created}; ${RECOVERY_DECODER_DIR:-not created}."
    if [ -n "${UNINSTALL_APPROVED_SOURCE:-}" ]; then
      log "Local authenticated retry entry: $UNINSTALL_APPROVED_SOURCE/bin/sls_mass_notify_uninstall.sh. Preserve the trust generation; no download is required."
    fi
  fi
  exit "$status"
}

rewrite_managed_text_file() {
  local path="$1" operation="$2" name="${3:-}" action="${4:-apply}"
  run_as_asterisk /usr/bin/python3 -I - "$path" "$operation" "$name" "$action" <<'PYREWRITE'
import fcntl
import os
import pwd
import re
import secrets
import stat
import sys

path, operation, name, action = sys.argv[1:]
parts = path.split('/')[1:]
if (not path.startswith('/') or not parts or any(part in ('', '.', '..') for part in parts)
        or operation not in ('menu', 'legacy-dashboard', 'managed-block')
        or action not in ('apply', 'check')):
    raise SystemExit('Invalid managed text rewrite request')
account = pwd.getpwnam('asterisk')
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', directory_flags)
descriptor = -1
temporary = None
try:
    for component in parts[:-1]:
        child = os.open(component, directory_flags, dir_fd=parent)
        metadata = os.fstat(child)
        if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in (0, account.pw_uid)
                or metadata.st_gid not in (0, account.pw_gid) or metadata.st_mode & 0o002):
            os.close(child)
            raise RuntimeError('managed text parent is unsafe')
        os.close(parent)
        parent = child
    try:
        descriptor = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC,
                             dir_fd=parent)
    except FileNotFoundError:
        raise SystemExit(0)
    before = os.fstat(descriptor)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_uid not in (0, account.pw_uid)
            or before.st_gid not in (0, account.pw_gid)
            or before.st_mode & 0o002
            or before.st_size > 8 * 1024 * 1024):
        raise RuntimeError('managed text file is unsafe or oversized')
    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
    body = bytearray()
    while len(body) <= 8 * 1024 * 1024:
        chunk = os.read(descriptor, min(65536, 8 * 1024 * 1024 + 1 - len(body)))
        if not chunk:
            break
        body.extend(chunk)
    after = os.fstat(descriptor)
    named = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    identity = lambda row: (row.st_dev, row.st_ino, row.st_uid, row.st_gid,
                            row.st_mode, row.st_size, row.st_mtime_ns,
                            row.st_ctime_ns, row.st_nlink)
    if len(body) > 8 * 1024 * 1024 or identity(before) != identity(after) or identity(after) != identity(named):
        raise RuntimeError('managed text file changed during inspection')
    try:
        data = bytes(body).decode('utf-8')
    except UnicodeDecodeError:
        raise RuntimeError('managed text file is not valid UTF-8')

    if operation == 'menu':
        updated = re.sub(
            r"\t// SLS Mass Notifications menu placement:.*?(?=\telse if \(\$a == 'other'\)\n\t\treturn (?:1|true);\n)",
            "", data, flags=re.S)
        for label in ('mass notifications', 'mass notify'):
            for block in (
                f"\telse if ($a == '{label}' && $b == 'other')\n\t\treturn -1;\n",
                f"\telse if ($a == 'other' && $b == '{label}')\n\t\treturn 1;\n",
                f"\telse if ($a == '{label}' && $b == 'user panel')\n\t\treturn 1;\n",
                f"\telse if ($a == 'user panel' && $b == '{label}')\n\t\treturn -1;\n",
                f"\telse if ($a == '{label}')\n\t\treturn 1;\n",
                f"\telse if ($b == '{label}')\n\t\treturn -1;\n",
            ):
                updated = updated.replace(block, '')
    elif operation == 'legacy-dashboard':
        updated = re.sub(
            r"\n\s*\$final\[\$i\]\s*=\s*\$this->checkSlsMassNotify\(\);\s*"
            r"\$final\[\$i\]\['title'\]\s*=\s*_\(\"Mass Notifications (?:Plugin|Module)\"\);\s*\$i\+\+;\s*",
            "\n", data, flags=re.S)
        updated = re.sub(
            r"\n\s*private function checkSlsMassNotify\(\)\s*\{.*?"
            r"(?=\n\s*private function genAlertGlyphicon\(\))",
            "\n", updated, flags=re.S)
    else:
        if not name or len(name.encode('utf-8')) > 160 or any(character in name for character in '\r\n\x00'):
            raise RuntimeError('invalid managed block name')
        updated = data
        def remove_complete_block(value, start_marker, end_marker):
            pattern = (r'(?m)^' + re.escape(start_marker) + r'\r?\n'
                       + r'(?s:.*?)^' + re.escape(end_marker) + r'(?:\r?\n|\Z)')
            return re.sub(pattern, '', value)
        for prefix in (';', '#'):
            updated = remove_complete_block(
                updated, prefix + ' BEGIN ' + name, prefix + ' END ' + name)
        updated = remove_complete_block(
            updated, ';-- BEGIN ' + name + ' --', ';-- END ' + name + ' --')

    if updated == data:
        raise SystemExit(0)
    encoded = updated.encode('utf-8')
    temporary = '.sls-uninstall-rewrite-' + secrets.token_hex(12)
    output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                     stat.S_IMODE(before.st_mode) & 0o777, dir_fd=parent)
    try:
        view = memoryview(encoded)
        while view:
            written = os.write(output, view)
            if written <= 0:
                raise OSError('short managed text write')
            view = view[written:]
        os.fchmod(output, stat.S_IMODE(before.st_mode) & 0o777)
        # Preserve the exact existing owner/group. The unprivileged rewrite
        # aborts rather than silently adopting a root-owned local file.
        os.fchown(output, before.st_uid, before.st_gid)
        os.fsync(output)
    finally:
        os.close(output)
    current = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    if identity(current) != identity(after):
        raise RuntimeError('managed text file changed before replacement')
    if action == 'check':
        raise SystemExit(0)
    os.replace(temporary, parts[-1], src_dir_fd=parent, dst_dir_fd=parent)
    temporary = None
    os.fsync(parent)
finally:
    if descriptor >= 0:
        os.close(descriptor)
    if temporary is not None:
        try:
            os.unlink(temporary, dir_fd=parent)
            os.fsync(parent)
        except FileNotFoundError:
            pass
    os.close(parent)
PYREWRITE
}

remove_menu_patch() {
  rewrite_managed_text_file "/var/www/html/admin/views/menu_items.php" menu
}

remove_legacy_dashboard_patch() {
  rewrite_managed_text_file "/var/www/html/admin/modules/dashboard/sections/Overview.class.php" legacy-dashboard
}

remove_managed_block() {
  local path="$1"
  local name="$2"
  rewrite_managed_text_file "$path" managed-block "$name"
}

verify_uninstall_collector_unit() {
  /usr/bin/python3 -I - "$1" "$RECOVERY_SIGNER_DIR/collector-unit.txt" <<'PY'
import os, stat, sys
fd = os.open(sys.argv[1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
with os.fdopen(fd, 'rb') as handle:
    before = os.fstat(handle.fileno())
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid != 0
            or before.st_mode & 0o022 or before.st_size > 65536):
        raise SystemExit('Refusing an unsafe collector service file; no service was changed.')
    body = handle.read(65537); after = os.fstat(handle.fileno()); named = os.lstat(sys.argv[1])
    identity = lambda row: (row.st_dev, row.st_ino, row.st_size, row.st_mtime_ns, row.st_ctime_ns, row.st_nlink)
    if identity(before) != identity(after) or identity(after) != identity(named) or body != open(sys.argv[2], 'rb').read():
        raise SystemExit('Collector service differs from its retained signed template. Preserve custom changes for review; no service was changed.')
PY
}

restore_uninstall_collector() {
  local unit="$1" previous="$2" enabled="$3" current
  if ! verify_uninstall_collector_unit "$unit"; then
    log 'Uninstall stopped before removing module files, but the collector service changed during recovery. Preserve its definition and history for review.'
    return 1
  fi
  current="$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=UnitFileState --value sls-mass-notify-phone-events.service)" || return 1
  if [ "$enabled" = enabled ] && [ "$current" = disabled ]; then
    /usr/bin/timeout --kill-after=2 20 /usr/bin/systemctl enable sls-mass-notify-phone-events.service || {
      log 'Uninstall stopped before removing module files, but the collector enablement could not be restored. Preserve its unit/history and restore its previous enabled state after reviewing systemd.'
      return 1
    }
  elif [ "$current" != "$enabled" ]; then
    log 'Collector enablement changed unexpectedly; no further service state was adopted. Preserve the unit and review systemd before retrying.'
    return 1
  fi
  [ "$previous" = active ] || return 0
  if ! /usr/bin/timeout --kill-after=2 20 /usr/bin/systemctl start sls-mass-notify-phone-events.service \
    || [ "$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" != active ]; then
    log 'Uninstall stopped before removing module files, but the previously active collector could not be restored. Preserve its service definition and history; review systemctl status/journalctl and restart the approved collector after resolving the named fault.'
    return 1
  fi
  log 'Uninstall stopped before removing module files; the previously active collector was restored without changing its enablement.'
}

remove_phone_collector_service() {
  local unit="/etc/systemd/system/sls-mass-notify-phone-events.service" active_state previous_state fragment dropins enabled_state
  [ -e "$unit" ] || [ -L "$unit" ] || return 0
  verify_uninstall_collector_unit "$unit" || return 1
  previous_state="$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" || return 1
  fragment="$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=FragmentPath --value sls-mass-notify-phone-events.service)" || return 1
  dropins="$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=DropInPaths --value sls-mass-notify-phone-events.service)" || return 1
  enabled_state="$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=UnitFileState --value sls-mass-notify-phone-events.service)" || return 1
  case "$previous_state:$enabled_state" in active:enabled|active:disabled|inactive:enabled|inactive:disabled|failed:enabled|failed:disabled) ;; *) log 'Collector enablement or process state is unsupported. No service was changed; review masks or custom supervision before retrying.'; return 1 ;; esac
  if [ "$fragment" != "$unit" ] || [ -n "$dropins" ]; then
    log 'Collector systemd fragments or overrides differ from the signed service. No service was changed; preserve custom supervision for review.'
    return 1
  fi
  if ! /usr/bin/timeout --kill-after=2 20 /usr/bin/systemctl stop sls-mass-notify-phone-events.service; then
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  active_state="$(/usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" || {
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  }
  case "$active_state" in inactive|failed) ;; *) log "The phone collector has not stopped; its service file was preserved."; restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true; return 1 ;; esac
  # Confirm shutdown did not leave a last voicemail call. Keep the service
  # definition and all runtime/history available if this final check fails.
  if ! assert_uninstall_workers || ! inspect_uninstall_idle; then
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  if ! /usr/bin/timeout --kill-after=2 20 /usr/bin/systemctl disable sls-mass-notify-phone-events.service; then
    log 'The collector stopped but its enablement could not be removed. Its service and module files remain; review systemd and retry.'
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  if ! rm -- "$unit"; then
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  /usr/bin/timeout --kill-after=2 10 /usr/bin/systemctl daemon-reload || {
    log 'The collector service was removed but systemd reload failed. Module and history remain; preserve signed recovery evidence, resolve systemd and retry the included uninstaller.'
    return 1
  }
}

remove_managed_root_file() {
  local path="$1" kind="$2" action="${3:-remove}"
  /usr/bin/python3 -I - "$path" "$kind" "$action" "$UNINSTALL_APPROVED_SOURCE/bin/sls_mass_notify/sls_privileged_install.py" <<'PYMANAGEDROOT'
import ast
import os
from pathlib import Path
import stat
import sys

path, kind, action, approved_helper = sys.argv[1:]
if kind not in ('apache-enabled', 'apache-available', 'logrotate') or action not in ('check', 'remove'):
    raise SystemExit('Unknown managed root-file type')

def protected_read(name, maximum):
    item = Path(name)
    for parent in reversed(item.parents):
        metadata = parent.lstat()
        if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0
                or metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX):
            raise RuntimeError('managed source has an unsafe parent')
    descriptor = os.open(item, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        before = os.fstat(descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1
                or before.st_mode & 0o022 or before.st_size > maximum):
            raise RuntimeError('managed source is unsafe')
        body = os.read(descriptor, maximum + 1)
        after = os.fstat(descriptor)
        if (len(body) != before.st_size or before.st_size != after.st_size
                or before.st_mtime_ns != after.st_mtime_ns or before.st_ctime_ns != after.st_ctime_ns):
            raise RuntimeError('managed source changed during inspection')
        return body
    finally:
        os.close(descriptor)

parts = path.split('/')[1:]
if not path.startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
    raise RuntimeError('invalid managed root-file path')
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', flags)
try:
    for component in parts[:-1]:
        child = os.open(component, flags, dir_fd=parent)
        metadata = os.fstat(child)
        if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_mode & 0o022:
            os.close(child)
            raise RuntimeError('managed root-file parent is unsafe')
        os.close(parent)
        parent = child
    try:
        before = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError:
        raise SystemExit(0)
    if kind == 'apache-enabled':
        expected = '../conf-available/sls-mass-notify.conf'
        if (not stat.S_ISLNK(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1
                or os.readlink(parts[-1], dir_fd=parent) != expected):
            raise RuntimeError('Apache enablement path is not the managed SLS link')
    else:
        descriptor = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC,
                             dir_fd=parent)
        try:
            opened = os.fstat(descriptor)
            if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0
                    or opened.st_nlink != 1 or opened.st_mode & 0o022 or opened.st_size > 1024 * 1024):
                raise RuntimeError('managed root file is unsafe')
            body = os.read(descriptor, 1024 * 1024 + 1)
            after = os.fstat(descriptor)
            named = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
            identity = lambda row: (row.st_dev, row.st_ino, row.st_uid, row.st_gid,
                                    row.st_mode, row.st_size, row.st_mtime_ns,
                                    row.st_ctime_ns, row.st_nlink)
            if len(body) != opened.st_size or identity(before) != identity(opened) \
                    or identity(opened) != identity(after) or identity(after) != identity(named):
                raise RuntimeError('managed root file changed during inspection')
        finally:
            os.close(descriptor)
        if kind == 'apache-available':
            tree = ast.parse(protected_read(approved_helper, 2 * 1024 * 1024), filename=approved_helper)
            expected = None
            for node in tree.body:
                if isinstance(node, (ast.Assign, ast.AnnAssign)):
                    targets = node.targets if isinstance(node, ast.Assign) else [node.target]
                    if any(isinstance(target, ast.Name) and target.id == 'APACHE' for target in targets):
                        expected = ast.literal_eval(node.value).encode()
                        break
            if expected is None or body != expected:
                raise RuntimeError('Apache configuration differs from the authenticated SLS template')
        else:
            match = __import__('re').fullmatch(
                rb'/var/log/sls_mass_notify\.log \{\n'
                rb'    daily\n'
                rb'    maxsize 2M\n'
                rb'    rotate ([0-9]{1,3})\n'
                rb'    maxage \1\n'
                rb'    missingok\n'
                rb'    notifempty\n'
                rb'    compress\n'
                rb'    delaycompress\n'
                rb'    copytruncate\n'
                rb'    su root asterisk\n'
                rb'\}\n', body)
            if match is None or not 1 <= int(match.group(1)) <= 365:
                raise RuntimeError('Logrotate configuration differs from an SLS-managed template')
    current = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    if (before.st_dev, before.st_ino, before.st_ctime_ns) != (current.st_dev, current.st_ino, current.st_ctime_ns):
        raise RuntimeError('managed root file changed before removal')
    if action == 'check':
        raise SystemExit(0)
    os.unlink(parts[-1], dir_fd=parent)
    os.fsync(parent)
finally:
    os.close(parent)
PYMANAGEDROOT
}

disable_apache_conf() {
  remove_managed_root_file /etc/logrotate.d/sls-mass-notify logrotate check
  remove_managed_root_file /etc/apache2/conf-enabled/sls-mass-notify.conf apache-enabled check
  remove_managed_root_file /etc/apache2/conf-available/sls-mass-notify.conf apache-available check
  remove_managed_root_file /etc/logrotate.d/sls-mass-notify logrotate
  remove_managed_root_file /etc/apache2/conf-enabled/sls-mass-notify.conf apache-enabled
  remove_managed_root_file /etc/apache2/conf-available/sls-mass-notify.conf apache-available
  rm -f /var/lib/apache2/conf/enabled_by_admin/sls-mass-notify
  rm -f /var/lib/apache2/conf/disabled_by_admin/sls-mass-notify
  /usr/bin/systemctl reload apache2 >/dev/null 2>&1 || log "Apache configuration was removed, but Apache reload failed. Reload Apache after reviewing its configuration."
}

preflight_uninstall_integrations() {
  local unit="/etc/systemd/system/sls-mass-notify-phone-events.service"
  if [ -e "$unit" ] || [ -L "$unit" ]; then
    verify_uninstall_collector_unit "$unit"
  fi
  rewrite_managed_text_file /var/www/html/admin/views/menu_items.php menu "" check
  rewrite_managed_text_file /var/www/html/admin/modules/dashboard/sections/Overview.class.php legacy-dashboard "" check
  rewrite_managed_text_file /etc/asterisk/sip_notify_custom.conf managed-block "SLS Mass Notifications SIP NOTIFY Templates" check
  rewrite_managed_text_file /etc/asterisk/extensions_custom.conf managed-block "SLS Mass Notifications Dialplan" check
  rewrite_managed_text_file /etc/asterisk/manager_custom.conf managed-block "SLS Mass Notifications AMI" check
  remove_managed_root_file /etc/logrotate.d/sls-mass-notify logrotate check
  remove_managed_root_file /etc/apache2/conf-enabled/sls-mass-notify.conf apache-enabled check
  remove_managed_root_file /etc/apache2/conf-available/sls-mass-notify.conf apache-available check
}

remove_freepbx_manager_users() {
  run_as_asterisk "SLS_CONFIGURED_AMI_USERNAME=$CONFIGURED_AMI_USERNAME" "SLS_AMI_SECRET_HASH=$CONFIGURED_AMI_SECRET_HASH" "SLS_AMI_OWNERSHIP_HELPER=$RECOVERY_AMI_OWNERSHIP_HELPER" /usr/bin/php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$candidates = ['slsmassnotify', 'sls_mass_notify', 'nws_push'];
$configured = (string)getenv('SLS_CONFIGURED_AMI_USERNAME');
if ($configured !== '') { $candidates[] = $configured; }
$candidates = array_values(array_unique(array_filter($candidates)));
require_once (string)getenv('SLS_AMI_OWNERSHIP_HELPER');
\SLS\MassNotify\AmiUserOwnership::remove(\FreePBX::Database(), $candidates,
    (string)getenv('SLS_AMI_SECRET_HASH'), (string)\FreePBX::Config()->get('AMPMGRUSER'));
exit(0);
PHP
  rm -f /etc/asterisk/slsmassnotify
}

remove_bundled_system_recordings() {
  run_as_asterisk "SLS_DATA_DIR=$DATA_DIR" /usr/bin/php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$dataDir = rtrim((string)getenv('SLS_DATA_DIR'), '/');
$recordings = [
    ['custom/SLS_Mass_Notify_Paging_Tone_Opening', '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Opening.wav', $dataDir . '/sounds/tones/opening_Paging_Tone_Opening.wav', 'SLS Mass Notify - Paging Tone Opening', 'Default Southland Servers regular announcement opening tone.'],
    ['custom/SLS_Mass_Notify_Paging_Tone_Closing', '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Closing.wav', $dataDir . '/sounds/tones/closing_Paging_Tone_Closing.wav', 'SLS Mass Notify - Paging Tone Closing', 'Default Southland Servers regular announcement closing tone.'],
    ['custom/SLS_Mass_Notify_NWS_Alert', '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_NWS_Alert.wav', $dataDir . '/sounds/tones/opening_NWS_alert.wav', 'SLS Mass Notify - NWS Alert', 'Default Southland Servers NWS alert opening tone.'],
    ['custom/SLS_Mass_Notify_Lightning_Alert', '/var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Lightning_Alert.wav', $dataDir . '/sounds/tones/opening_Lightning_alert.wav', 'SLS Mass Notify - Lightning Alert', 'Default Southland Servers cloud-to-ground lightning warning opening tone.'],
];
$database = \FreePBX::Database();
$tables = $database->query("SHOW TABLES LIKE 'recordings'");
if ($tables->fetchColumn() === false) { exit(0); }
$lookup = $database->prepare('SELECT displayname, description FROM recordings WHERE filename = ? LIMIT 1');
$delete = $database->prepare('DELETE FROM recordings WHERE filename = ?');
foreach ($recordings as [$filename, $path, $tone, $name, $description]) {
    $fileExists = is_file($path) && !is_link($path);
    $toneHash = is_file($tone) ? @hash_file('sha256', $tone) : false;
    $fileHash = $fileExists ? @hash_file('sha256', $path) : false;
    $fileOwned = is_string($toneHash) && $toneHash !== '' && is_string($fileHash) && hash_equals($toneHash, $fileHash);
    $lookup->execute([$filename]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    $rowOwned = is_array($row)
        && hash_equals($name, (string)($row['displayname'] ?? ''))
        && hash_equals($description, (string)($row['description'] ?? ''));
    if ($rowOwned && (!$fileExists || $fileOwned)) {
        $delete->execute([$filename]);
    }
    if ($fileOwned && (!is_array($row) || $rowOwned)) {
        @unlink($path);
    }
}
exit(0);
PHP
}

verify_freepbx_cleanup() {
  inspect_module_registry || return 1
  if [ "$MODULE_REGISTRY_PRESENT" = 1 ]; then
    log "Uninstall verification failed; the $MODULE FreePBX registry row remains."
    return 1
  fi
  run_as_asterisk "SLS_CONFIGURED_AMI_USERNAME=$CONFIGURED_AMI_USERNAME" "SLS_AMI_SECRET_HASH=$CONFIGURED_AMI_SECRET_HASH" "SLS_AMI_OWNERSHIP_HELPER=$RECOVERY_AMI_OWNERSHIP_HELPER" /usr/bin/php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$candidates = ['slsmassnotify', 'sls_mass_notify', 'nws_push'];
$configured = (string)getenv('SLS_CONFIGURED_AMI_USERNAME');
if ($configured !== '') { $candidates[] = $configured; }
$candidates = array_values(array_unique(array_filter($candidates)));
$database = \FreePBX::Database();
require_once (string)getenv('SLS_AMI_OWNERSHIP_HELPER');
$statement = $database->prepare('SELECT * FROM manager WHERE name = ?');
foreach ($candidates as $username) {
    if ($username === (string)\FreePBX::Config()->get('AMPMGRUSER')) { continue; }
    $statement->execute([$username]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && \SLS\MassNotify\AmiUserOwnership::matches($row, $username, (string)getenv('SLS_AMI_SECRET_HASH'))) {
        file_put_contents("php://stderr", "FreePBX AMI user remains after uninstall: {$username}\n");
        exit(1);
    }
}
exit(0);
PHP
  local stock_module
  for stock_module in dashboard framework; do
    [ -d "/var/www/html/admin/modules/$stock_module" ] || continue
    if ! verify_stock_module "$stock_module"; then
      log "Uninstall verification failed; FreePBX did not trust the restored $stock_module module."
      return 1
    fi
  done
  local artifact
  for artifact in \
    /etc/apache2/conf-enabled/sls-mass-notify.conf \
    /etc/apache2/conf-available/sls-mass-notify.conf \
    /var/lib/apache2/conf/enabled_by_admin/sls-mass-notify \
    /var/lib/apache2/conf/disabled_by_admin/sls-mass-notify \
    /etc/asterisk/slsmassnotify \
    /etc/systemd/system/sls-mass-notify-phone-events.service \
    /usr/local/bin/sls_mass_notify \
    /usr/local/sbin/sign_sls_mass_notify_local_sig.sh \
    /var/www/html/api/sipnotify \
    /var/www/html/api/sls-mass-notify \
    /var/www/html/mass-notify \
    /var/www/html/sls_mass_notify \
    /var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php \
    /var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php; do
    if [ -e "$artifact" ] || [ -L "$artifact" ]; then
      log "Uninstall verification failed; managed artifact remains: $artifact"
      return 1
    fi
  done
  verify_managed_sound_link_removed /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin || return 1
  verify_managed_sound_link_removed /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin || return 1
  local managed_file managed_name managed_marker
  while IFS='|' read -r managed_file managed_name; do
    for managed_marker in "; BEGIN $managed_name" "# BEGIN $managed_name" ";-- BEGIN $managed_name --"; do
      if grep -Fq "$managed_marker" "$managed_file" 2>/dev/null; then
        log "Uninstall verification failed; a managed Asterisk block remains in $managed_file."
        return 1
      fi
    done
  done <<'SLSMANAGEDFILES'
/etc/asterisk/sip_notify_custom.conf|SLS Mass Notifications SIP NOTIFY Templates
/etc/asterisk/extensions_custom.conf|SLS Mass Notifications Dialplan
/etc/asterisk/manager_custom.conf|SLS Mass Notifications AMI
SLSMANAGEDFILES
  verify_removed_cron || return 1
  if grep -Fq 'SLS Mass Notifications menu placement:' /var/www/html/admin/views/menu_items.php 2>/dev/null; then
    log "Uninstall verification failed; the managed FreePBX menu placement remains."
    return 1
  fi
  return 0
}

verify_stock_module() {
  local stock_module="$1"
  run_as_asterisk "SLS_VERIFY_MODULE=$stock_module" /usr/bin/php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$gpg = \FreePBX::GPG();
$gpg->timeout = 30;
$result = $gpg->verifyModule((string)getenv("SLS_VERIFY_MODULE"));
$valid = is_array($result)
    && array_key_exists("status", $result)
    && is_int($result["status"])
    && $result["status"] === 129
    && array_key_exists("details", $result)
    && is_array($result["details"])
    && count($result["details"]) === 0;
exit($valid ? 0 : 1);
'
}

locally_sign_stock_module() {
  local stock_module="$1"

  if [ -n "$RECOVERY_SIGNER" ]; then
    [ -f "$RECOVERY_SIGNER" ] && [ ! -L "$RECOVERY_SIGNER" ] && [ -x "$RECOVERY_SIGNER" ] || {
      log "The protected uninstall signer snapshot is missing or unsafe."
      return 1
    }
    if ! ( if [ -n "${UNINSTALL_WORKER_PIPE:-}" ]; then exec {UNINSTALL_WORKER_PIPE}>&-; fi
      SLS_LOCAL_SIGN_HOME="$SIGN_HOME" \
      SLS_LOCAL_SIGN_LOCK="/run/lock/sls-mass-notify-signing.lock" \
      SLS_LOCAL_TRUST_HELPER="$RECOVERY_SIGNER_DIR/sls_module_trust.py" \
      SLS_LOCAL_TRUST_MODE="uninstalled" \
      /usr/bin/timeout --signal=TERM 360 "$RECOVERY_SIGNER" "$stock_module" \
        >>"$STOCK_RESTORE_LOG" 2>&1 ); then
      log "The protected local signer could not verify the cleaned $stock_module module."
      return 1
    fi
    verify_stock_module "$stock_module" || return 1
    return 0
  fi

  # Older signers inventory the current web tree and would authorize unrelated
  # modifications. Never weaken trust when repository recovery is unavailable.
  log "No protected inventory-aware local signer is available for $stock_module. Preserve recovery files and restore a verified upstream module or reviewed baseline."
  return 1
}

remove_piper_wrapper() {
  /usr/bin/python3 -I - "/usr/local/bin/piper" "$UNINSTALL_APPROVED_SOURCE/bin/sls_mass_notify_install_piper_voices.sh" "$DATA_DIR/piper/venv/bin/piper" <<'PYPIPERREMOVE'
import fcntl
import os
import re
import stat
import sys

wrapper, approved_installer, legacy_target = sys.argv[1:]
runtime_target = '/usr/local/bin/sls_mass_notify/piper/venv/bin/piper'

def protected_read(path, maximum):
    for parent in reversed(__import__('pathlib').Path(path).parents):
        metadata = parent.lstat()
        if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0
                or metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX):
            raise RuntimeError('approved Piper source has an unsafe parent')
    descriptor = os.open(path, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        before = os.fstat(descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0
                or before.st_nlink != 1 or before.st_mode & 0o022 or before.st_size > maximum):
            raise RuntimeError('approved Piper source is unsafe')
        body = bytearray()
        while len(body) <= maximum:
            chunk = os.read(descriptor, min(65536, maximum + 1 - len(body)))
            if not chunk:
                break
            body.extend(chunk)
        after = os.fstat(descriptor)
        if (len(body) != before.st_size or before.st_size != after.st_size
                or before.st_mtime_ns != after.st_mtime_ns or before.st_ctime_ns != after.st_ctime_ns):
            raise RuntimeError('approved Piper source changed during inspection')
        return bytes(body)
    finally:
        os.close(descriptor)

parent = os.open('/usr/local/bin', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
try:
    try:
        before = os.stat('piper', dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError:
        raise SystemExit(0)
    if stat.S_ISLNK(before.st_mode):
        target = os.readlink('piper', dir_fd=parent)
        if before.st_uid == 0 and before.st_nlink == 1 and target in (runtime_target, legacy_target):
            current = os.stat('piper', dir_fd=parent, follow_symlinks=False)
            if (before.st_dev, before.st_ino, before.st_ctime_ns) != (current.st_dev, current.st_ino, current.st_ctime_ns):
                raise RuntimeError('Piper wrapper link changed before removal')
            os.unlink('piper', dir_fd=parent)
            os.fsync(parent)
        else:
            print('Leaving non-SLS Piper symbolic link unchanged.', file=sys.stderr)
        raise SystemExit(0)
    match = re.search(rb"expected=\"\$\(cat <<'EOF'\n(.*?)\nEOF\n\)\"", protected_read(approved_installer, 2 * 1024 * 1024), re.S)
    if match is None:
        raise RuntimeError('approved Piper wrapper template is unavailable')
    descriptor = os.open('piper', os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC,
                         dir_fd=parent)
    try:
        opened = os.fstat(descriptor)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0
                or opened.st_nlink != 1 or stat.S_IMODE(opened.st_mode) != 0o755
                or opened.st_size > 16384):
            print('Leaving non-SLS Piper wrapper unchanged.', file=sys.stderr)
            raise SystemExit(0)
        fcntl.flock(descriptor, fcntl.LOCK_SH | fcntl.LOCK_NB)
        body = os.read(descriptor, 16385)
        after = os.fstat(descriptor)
        current = os.stat('piper', dir_fd=parent, follow_symlinks=False)
        identity = lambda row: (row.st_dev, row.st_ino, row.st_uid, row.st_gid,
                                row.st_mode, row.st_size, row.st_mtime_ns,
                                row.st_ctime_ns, row.st_nlink)
        if identity(before) != identity(opened) or identity(opened) != identity(after) or identity(after) != identity(current):
            raise RuntimeError('Piper wrapper changed during inspection')
        if body != match.group(1) + b'\n':
            print('Leaving modified Piper wrapper unchanged.', file=sys.stderr)
            raise SystemExit(0)
    finally:
        os.close(descriptor)
    os.unlink('piper', dir_fd=parent)
    os.fsync(parent)
finally:
    os.close(parent)
PYPIPERREMOVE
}

remove_managed_sound_link() {
  local link_path="$1"
  local expected_target="$DATA_DIR/sounds"
  local actual_target=""

  if [ -L "$link_path" ]; then
    actual_target="$(readlink -- "$link_path" 2>/dev/null || true)"
    if [ "$actual_target" = "$expected_target" ]; then
      rm -f -- "$link_path"
    else
      log "Leaving non-module sound link unchanged: $link_path"
    fi
  elif [ -e "$link_path" ]; then
    # A managed install creates a symbolic link here. Never recursively delete
    # a real directory or file that may contain user-owned recordings.
    log "Leaving non-link sound path unchanged: $link_path"
  fi
}

verify_managed_sound_link_removed() {
  local link_path="$1"
  if [ -L "$link_path" ] && [ "$(readlink -- "$link_path" 2>/dev/null || true)" = "$DATA_DIR/sounds" ]; then
    log "Uninstall verification failed; managed sound link remains: $link_path"
    return 1
  fi
}

remove_runtime_files() {
  remove_piper_wrapper
  if [ -L "$DATA_DIR/piper/venv/bin/piper" ] && [ "$(readlink "$DATA_DIR/piper/venv/bin/piper")" = "/usr/local/bin/piper" ]; then
    rm -f "$DATA_DIR/piper/venv/bin/piper"
    rmdir "$DATA_DIR/piper/venv/bin" "$DATA_DIR/piper/venv" 2>/dev/null || true
  fi
  rm -rf /usr/local/bin/sls_mass_notify
  rm -f /usr/local/bin/slsconsole
  rm -f /usr/local/bin/nwsalerts_ensure_menu_patch.sh
  rm -f /usr/local/sbin/sign_sls_mass_notify_local_sig.sh
  rm -f /etc/freepbx.secure/slsmassnotifyserver.sig
  remove_managed_sound_link /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin
  remove_managed_sound_link /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin
  rm -f /var/lib/asterisk/bin/sls_mass_notify
  rm -f /var/lib/asterisk/bin/sls_mass_notify_test.sh
  rm -rf /var/www/html/api/sls-mass-notify
  rm -rf /var/www/html/api/sipnotify
  rm -rf /var/www/html/mass-notify
  rm -rf /var/www/html/sls_mass_notify
  if [ "$PURGE_CONFIG" = 1 ]; then
    rm -rf "$DATA_DIR"
    rm -f /var/log/sls_mass_notify.log
    rm -f /var/log/sls_mass_notify_events.jsonl
    rm -f /var/log/sls_mass_notify_push.log
  fi
  # Leave downloaded installers and unrelated recovery directories untouched;
  # remove only the obsolete SLS weather-poller timestamp.
  rm -f /var/tmp/nws_last_clear.ts
}

read_uninstall_crontab() {
  local user="$1" target="$2" error_file result=0 diagnostic
  [[ "$user" = root || "$user" = asterisk ]] || return 1
  error_file="$(mktemp /tmp/slsmassnotifyserver-cron-errors.XXXXXX)" || return 1
  LC_ALL=C crontab -u "$user" -l >"$target" 2>"$error_file" || result=$?
  diagnostic="$(cat "$error_file")"
  rm -f "$error_file"
  if [ "$result" = 0 ]; then return 0; fi
  if [ "$result" = 1 ] && [ "$diagnostic" = "no crontab for $user" ]; then
    : >"$target"
    return 0
  fi
  log "The $user crontab could not be read. No cron replacement was authorized; restore cron access and retry."
  return 1
}

filter_uninstall_crontab() {
  /usr/bin/python3 -I - "$1" "$2" <<'PYCRON'
from pathlib import Path
import re, sys
source, target = map(Path, sys.argv[1:])
pattern = re.compile(rb'/usr/local/bin/sls_mass_notify/sls_cron_runner\.py|sls_mass_notify_(?:weather_poll|nws_poll|update|maintenance)\.sh|sls_mass_notify_(?:schedule|announcement)_worker\.php|sls_announcement_worker\.py|nwsalerts_ensure_menu_patch\.sh')
body = source.read_bytes()
if len(body) > 8 * 1024 * 1024: raise SystemExit('The crontab exceeds the bounded uninstall review size')
lines = body.splitlines(keepends=True)
target.write_bytes(b''.join(line for line in lines if line.lstrip().startswith(b'#') or not pattern.search(line)))
PYCRON
}

remove_cron() {
  local user restore_user work rollback_failed=0
  local -a changed_users=()
  work="$(mktemp -d /tmp/slsmassnotifyserver-cron.XXXXXX)" || return 1
  # Both reads succeed before either user's crontab may be replaced.
  for user in root asterisk; do
    if ! read_uninstall_crontab "$user" "$work/$user.before" \
      || ! filter_uninstall_crontab "$work/$user.before" "$work/$user.after"; then
      rm -rf "$work"
      return 1
    fi
  done
  for user in root asterisk; do
    if ! read_uninstall_crontab "$user" "$work/$user.current" \
      || ! cmp -s "$work/$user.before" "$work/$user.current"; then
      log "The $user crontab changed during uninstall preparation. No replacement was started; preserve the administrator's edits and retry."
      rm -rf "$work"
      return 1
    fi
  done
  for user in root asterisk; do
    cmp -s "$work/$user.before" "$work/$user.after" && continue
    if ! crontab -u "$user" "$work/$user.after" 2>/dev/null; then
      for restore_user in "${changed_users[@]}"; do
        if ! crontab -u "$restore_user" "$work/$restore_user.before" 2>/dev/null; then
          rollback_failed=1
          log "The $restore_user crontab could not be restored after the $user replacement failed. Its authenticated original remains in $work."
        fi
      done
      if [ "$rollback_failed" = 1 ]; then
        log "The $user SLS cron entries could not be removed and cron rollback was incomplete. Preserve $work and restore the named crontab before retrying."
      else
        log "The $user SLS cron entries could not be removed. Earlier replacements were rolled back; original crontabs remain in $work for review."
      fi
      return 1
    fi
    changed_users+=("$user")
  done
  rm -rf "$work"
}

verify_uninstall_cron_access() {
  local user work
  work="$(mktemp -d /tmp/slsmassnotifyserver-cron-preflight.XXXXXX)" || return 1
  for user in root asterisk; do
    if ! read_uninstall_crontab "$user" "$work/$user.before"; then
      rm -rf "$work"
      return 1
    fi
  done
  rm -rf "$work"
}

verify_removed_cron() {
  local user work
  work="$(mktemp -d /tmp/slsmassnotifyserver-cron-verify.XXXXXX)" || return 1
  for user in root asterisk; do
    if ! read_uninstall_crontab "$user" "$work/$user.before" \
      || ! filter_uninstall_crontab "$work/$user.before" "$work/$user.after"; then
      rm -rf "$work"
      return 1
    fi
    if ! cmp -s "$work/$user.before" "$work/$user.after"; then
      rm -rf "$work"
      log "Uninstall verification failed; an SLS scheduled job remains in the $user crontab."
      return 1
    fi
  done
  rm -rf "$work"
}

restore_stock_modules() {
  rm -f /var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php
  rm -f /var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php
  remove_legacy_dashboard_patch
  local stock_module
  for stock_module in dashboard framework; do
    [ -d "/var/www/html/admin/modules/$stock_module" ] || continue
    # Preserve independently reviewed branding and dependency hardening.
    # Only the approved uninstall variant may be signed; never replace the
    # whole Framework/Dashboard tree or learn a new baseline from live files.
    if ! locally_sign_stock_module "$stock_module"; then
      log "The cleaned $stock_module module differs from its reviewed uninstall inventory. Preserved review artifacts and log: $STOCK_RESTORE_LOG_PATH"
      return 1
    fi
    KEEP_SIGNING_TRUST=1
    log "Preserved and verified approved $stock_module files after removing SLS integration."
  done
}

refresh_dashboard_hook_index() {
  run_as_asterisk /usr/bin/php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$hooksFile = '/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php';
if (!is_readable($hooksFile)) {
    file_put_contents("php://stderr", "Dashboard hook loader is missing after uninstall.\n");
    exit(1);
}
require_once $hooksFile;
$dashboard = \FreePBX::Dashboard();
$visualOrder = $dashboard->getConfig('visualorder');
$hooks = \DashboardHooks::genHooks(is_array($visualOrder) ? $visualOrder : []);
foreach ((array)$hooks as $page) {
    foreach ((array)($page['entries'] ?? []) as $entry) {
        if (($entry['rawname'] ?? '') === 'SlsMassNotifyAnnouncement') {
            file_put_contents("php://stderr", "Removed Mass Notify Dashboard hook is still discoverable.\n");
            exit(1);
        }
    }
}
$dashboard->setConfig('allhooks', $hooks);
exit(0);
PHP
}

remove_trusted_signing_key() {
  if [ "$KEEP_SIGNING_TRUST" = "1" ]; then
    # Reviewed stock modules retain approved branding and hardening overlays.
    # Delete private signing material but retain the public verification key.
    rm -rf "$SIGN_HOME"
    log "The temporary private signing key was removed; only its public verification key was retained."
    return 0
  fi
  [ -n "$SIGNING_FINGERPRINT" ] || {
    rm -rf "$SIGN_HOME"
    return 0
  }
  local home
  local user
  local command
  for home in /root/.gnupg /home/asterisk/.gnupg /var/lib/asterisk/.gnupg; do
    [ -d "$home" ] || continue
    if [ "$home" = "/root/.gnupg" ]; then
      GNUPGHOME="$home" gpg --batch --yes --delete-key "$SIGNING_FINGERPRINT" >/dev/null 2>&1 || true
      printf '%s:2:\n' "$SIGNING_FINGERPRINT" | GNUPGHOME="$home" gpg --import-ownertrust >/dev/null 2>&1 || true
    else
      user="asterisk"
      command="GNUPGHOME=$(printf '%q' "$home") gpg --batch --yes --delete-key $(printf '%q' "$SIGNING_FINGERPRINT") >/dev/null 2>&1 || true"
      su -s /bin/bash "$user" -c "$command" || true
      command="printf '%s:2:\\n' $(printf '%q' "$SIGNING_FINGERPRINT") | GNUPGHOME=$(printf '%q' "$home") gpg --import-ownertrust >/dev/null 2>&1 || true"
      su -s /bin/bash "$user" -c "$command" || true
    fi
  done
  rm -rf "$SIGN_HOME"
}

admit_root_runtime() {
  UNINSTALL_APPROVED_SOURCE="$(/usr/bin/python3 -I - <<'PYGUARD'
import hashlib, json, os, re, stat, sys, types
from pathlib import Path
sys.dont_write_bytecode = True
root = Path('/var/lib/sls-mass-notify-trust')
def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result: raise RuntimeError('Duplicate uninstall evidence member')
        result[key] = value
    return result
def decode(body):
    return json.loads(body, object_pairs_hook=unique_object,
                      parse_constant=lambda value: (_ for _ in ()).throw(RuntimeError('Non-finite uninstall evidence number')))
def read(path, maximum):
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX:
            raise RuntimeError('Uninstall evidence has an unprotected parent')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1 or info.st_size > maximum:
            raise RuntimeError('Uninstall evidence is unsafe: ' + str(path))
        body = stream.read(maximum + 1); after = os.fstat(stream.fileno())
        if len(body) != info.st_size or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('Uninstall evidence changed during inspection')
        return body
reference = decode(read(root / 'slsmassnotifyserver.active.json', 1024))
if not isinstance(reference, dict): raise SystemExit('Invalid protected SLS publisher reference')
digest = reference.get('sha256', '')
if not isinstance(digest, str) or not re.fullmatch('[a-f0-9]{64}', digest) or reference.get('generation') != 'slsmassnotifyserver-' + digest:
    raise SystemExit('Invalid protected SLS publisher generation')
generation = root / reference['generation']
raw = read(generation / 'inventory.json', 8 * 1024 * 1024)
if hashlib.sha256(raw).hexdigest() != digest: raise SystemExit('Protected SLS publisher inventory changed')
inventory = decode(raw)
if not isinstance(inventory, dict) or not isinstance(inventory.get('files'), dict): raise SystemExit('Invalid protected SLS publisher inventory')
name = 'bin/sls_mass_notify/sls_module_trust.py'
entry = inventory.get('files', {}).get(name)
path = generation / 'module' / name
body = read(path, 2 * 1024 * 1024)
if not isinstance(entry, dict) or hashlib.sha256(body).hexdigest() != entry.get('sha256'):
    raise SystemExit('Protected SLS trust helper changed')
trust = types.ModuleType('sls_uninstall_trust'); trust.__file__ = str(path)
exec(compile(body, str(path), 'exec'), trust.__dict__)
data, approved_generation = trust.load(root, 'slsmassnotifyserver')
if approved_generation != generation or data['source']['kind'] != 'publisher-ed25519':
    raise SystemExit('Uninstall requires an existing publisher-approved generation')
required = {
    'bin/sls_mass_notify_uninstall.sh':'/usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh',
    'bin/sign_sls_mass_notify_local_sig.sh':'/usr/local/sbin/sign_sls_mass_notify_local_sig.sh',
    'bin/sls_mass_notify/sls_module_trust.py':'/usr/local/bin/sls_mass_notify/sls_module_trust.py',
    'bin/sls_mass_notify/sls_config_crypto.php':'/usr/local/bin/sls_mass_notify/sls_config_crypto.php',
    'bin/sls_mass_notify/sls_install_guard.py':'/usr/local/bin/sls_mass_notify/sls_install_guard.py',
    'bin/sls_mass_notify/sls_install_idle.py':'/usr/local/bin/sls_mass_notify/sls_install_idle.py',
    'bin/sls_mass_notify/sls_audio_state.py':'/usr/local/bin/sls_mass_notify/sls_audio_state.py',
    'bin/sls_mass_notify/sls_config_crypto.py':'/usr/local/bin/sls_mass_notify/sls_config_crypto.py',
    'bin/slsconsole':'/usr/local/bin/slsconsole',
}
optional = 'bin/sls_mass_notify/sls_legacy_permissions.py'
if optional in data['files']: required[optional] = '/usr/local/bin/sls_mass_notify/sls_legacy_permissions.py'
for name, installed in required.items():
    entry = data['files'].get(name)
    if not entry: raise SystemExit('Required signed uninstall helper is absent: ' + name)
    approved = trust.read(generation / 'module' / name, 2 * 1024 * 1024, protected=True)
    if trust.digest(approved) != entry['sha256']: raise SystemExit('Required signed uninstall helper changed: ' + name)
    try: actual = read(Path(installed), 2 * 1024 * 1024)
    except FileNotFoundError: continue
    if actual != approved: raise SystemExit('Installed uninstall helper differs from its signed release: ' + installed)
print(generation / 'module')
PYGUARD
)" || return 1
}

remove_sls_chown_exclusion() {
  # The required teardown helpers were publisher-verified before mutation. Remove
  # only this module's exact managed native exclusion before deleting its helper.
  local helper="$RECOVERY_SIGNER_DIR/sls_legacy_permissions.py"
  if [ -f "$helper" ] && [ ! -L "$helper" ]; then
    /usr/bin/python3 -I "$helper" --chown-config /etc/asterisk/freepbx_chown.conf remove-chown
  elif grep -Fq 'BEGIN SLS MASS NOTIFY DATA PERMISSIONS' /etc/asterisk/freepbx_chown.conf 2>/dev/null; then
    log 'The installed release does not approve a helper for this newer chown exclusion. Preserve the native configuration for review.'
    return 1
  fi
}

verify_uninstall_inventories() {
  local module_dir='/var/www/html/admin/modules/slsmassnotifyserver' reviewed_module
  local helper="$RECOVERY_SIGNER_DIR/sls_module_trust.py"
  inspect_module_registry || return 1
  if [ -e "$module_dir" ] || [ -L "$module_dir" ]; then
    /usr/bin/python3 -I "$helper" --root /var/lib/sls-mass-notify-trust check --module slsmassnotifyserver --web-root /var/www/html || {
      log "The installed SLS module differs from its signed inventory. Preserve the changed files for review before retrying."
      return 1
    }
  elif [ "$MODULE_REGISTRY_PRESENT" = 1 ]; then
    log "The SLS directory is missing but its FreePBX registry row remains. Preserve the signed release evidence and repair this incomplete removal before retrying."
    return 1
  fi
  for reviewed_module in dashboard framework; do
    if /usr/bin/python3 -I "$helper" --root /var/lib/sls-mass-notify-trust check --module "$reviewed_module" --web-root /var/www/html >/dev/null 2>&1; then
      continue
    fi
    /usr/bin/python3 -I "$helper" --root /var/lib/sls-mass-notify-trust check --module "$reviewed_module" --web-root /var/www/html --uninstalled || {
      log "The $reviewed_module files match neither their approved installed nor approved uninstall inventory. Preserve local changes for independent review."
      return 1
    }
  done
}

main() {
  require_freepbx
  admit_root_runtime || { log "Signed uninstall evidence is missing or changed. Preserve the trust store and review the named file; no uninstall changes were made. Missing stock inventories require independently verified local packages, not approval of live hashes."; return 1; }
  acquire_maintenance_coordination
  prepare_uninstall_logs
  trap handle_uninstall_exit EXIT
  snapshot_local_signer
  [ -n "$RECOVERY_SIGNER" ] || { log "An approved-inventory signer is required before uninstall can preserve the reviewed FreePBX modules."; return 1; }
  verify_uninstall_inventories
  verify_uninstall_cron_access
  capture_signing_fingerprint
  validate_retained_user_data
  preflight_uninstall_integrations
  capture_pbx_running_state
  acquire_uninstall_workers
  inspect_uninstall_idle
  capture_configured_ami_username
  UNINSTALL_TRANSACTION_DIR="$(uninstall_recovery_workspace sls-uninstall-transaction)"
  record_uninstall_stage prepared
  log "Persistent uninstall transaction: $UNINSTALL_TRANSACTION_DIR"
  assert_uninstall_workers
  record_uninstall_stage services
  remove_phone_collector_service
  assert_uninstall_workers
  remove_freepbx_manager_users
  assert_uninstall_workers
  record_uninstall_stage module
  remove_module_registration
  assert_uninstall_workers
  remove_bundled_system_recordings
  remove_cron
  record_uninstall_stage integration
  remove_menu_patch
  remove_legacy_dashboard_patch
  remove_managed_block /etc/asterisk/sip_notify_custom.conf "SLS Mass Notifications SIP NOTIFY Templates"
  remove_managed_block /etc/asterisk/extensions_custom.conf "SLS Mass Notifications Dialplan"
  remove_managed_block /etc/asterisk/manager_custom.conf "SLS Mass Notifications AMI"
  disable_apache_conf
  restore_stock_modules
  refresh_dashboard_hook_index
  remove_sls_chown_exclusion
  assert_uninstall_workers
  record_uninstall_stage runtime
  remove_runtime_files
  remove_trusted_signing_key
  record_uninstall_stage verification
  verify_freepbx_cleanup
  reload_removed_pbx_integration
  remove_freepbx_manager_users
  if [ "$PBX_RUNNING" = 1 ]; then
    asterisk -rx "manager reload" >/dev/null 2>&1 || true
  fi
  verify_freepbx_cleanup
  assert_uninstall_workers
  record_uninstall_stage complete
  cleanup_recovery_signer
  trap - EXIT
  if [ "$PURGE_CONFIG" = "1" ]; then
    log "SLS Mass Notify uninstall cleanup finished. Configuration and user data were purged."
  else
    log "SLS Mass Notify uninstall cleanup finished. Central config, media, delivery history and audit logs were preserved."
  fi
  if [ -e /etc/sls-mass-notify/config-keys.json ] || [ -L /etc/sls-mass-notify/config-keys.json ]; then
    log "Configuration recovery keys were retained at /etc/sls-mass-notify/config-keys.json, including after a data purge. Keep this protected keyring while older encrypted configurations or backups may still be needed."
  fi
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
