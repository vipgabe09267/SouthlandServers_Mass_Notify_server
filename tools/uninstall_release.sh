#!/usr/bin/env bash
set -euo pipefail

umask 027

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
CONFIG_TMP=""
SIGNING_FINGERPRINT=""
KEEP_SIGNING_TRUST=0
RECOVERY_SIGNER_DIR=""
RECOVERY_SIGNER=""
UNINSTALL_APPROVED_SOURCE=""
CONFIGURED_AMI_USERNAME=""
RECOVERY_DECODER_DIR=""
RECOVERY_CONFIG_DECODER="/usr/local/bin/sls_mass_notify/sls_config_crypto.php"
UNINSTALL_MAINTENANCE_LOCK_FD=""
STOCK_RESTORE_LOG_PATH="/tmp/slsmassnotifyserver-uninstall-stock-modules.log"
MODULE_UNINSTALL_LOG_PATH="/tmp/slsmassnotifyserver-uninstall-module.log"
STOCK_RESTORE_LOG="$STOCK_RESTORE_LOG_PATH"
MODULE_UNINSTALL_LOG="$MODULE_UNINSTALL_LOG_PATH"
STOCK_RESTORE_LOG_FD=""
MODULE_UNINSTALL_LOG_FD=""
MODULE_REGISTRY_PRESENT=0
PBX_RUNNING=0
PBX_RELOAD_WARNING=0
UNINSTALL_WORKER_PID=""
UNINSTALL_WORKER_PIPE=""
UNINSTALL_GUARD_REQUIRED=0

# Open only a root-owned regular file through trusted directories. Creation is
# exclusive; an existing file is never truncated or written before fd identity
# verification. Sticky root-owned /tmp and /run/lock are permitted. Only log
# callers may adopt a legacy asterisk-owned regular file; locks remain strict.
open_root_owned_file() {
  local output_variable="$1" protected_path="$2" file_purpose="${3:-lock}"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 - "$protected_path" "$file_purpose" <<'PY'
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
  /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 -r '
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
  local descriptor descriptor_path descriptor_target

  [[ "$lock_file" = /* ]] && [ "$lock_file" != "/" ] || {
    log "Unsafe maintenance lock path."
    return 1
  }
  mkdir -p "$(dirname "$lock_file")"
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

snapshot_local_signer() {
  local candidate=""
  local trust_helper="${SLS_MASS_NOTIFY_TRUST_HELPER_SOURCE:-/usr/local/bin/sls_mass_notify/sls_module_trust.py}"
  local -a candidates=()

  if [ -n "$UNINSTALL_APPROVED_SOURCE" ]; then
    RECOVERY_SIGNER_DIR="$(mktemp -d /tmp/sls-uninstall-signer.XXXXXX)" || return 1
    chmod 0700 "$RECOVERY_SIGNER_DIR" || return 1
    RECOVERY_DECODER_DIR="$(mktemp -d /tmp/sls-uninstall-decoder.XXXXXX)" || return 1
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
PYAPPROVEDSNAPSHOT
    RECOVERY_SIGNER="$RECOVERY_SIGNER_DIR/sign_sls_mass_notify_local_sig.sh"
    RECOVERY_CONFIG_DECODER="$RECOVERY_DECODER_DIR/sls_config_crypto.php"
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
    RECOVERY_SIGNER_DIR="$(mktemp -d /tmp/sls-uninstall-signer.XXXXXX)" || return 1
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
    if len(body) > 256 * 1024 or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
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
  /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 /usr/sbin/fwconsole "$@"
)

module_registry_exists() {
  SLS_MODULE_NAME="$MODULE" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 -r '
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
    SLS_MODULE_NAME="$MODULE" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 -r '
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
    PBX_RELOAD_WARNING=1
    log "SLS removal was verified. Asterisk is stopped or its control socket is unavailable; run fwconsole reload after PBX service is available. No PBX service was started."
    return 0
  fi
  if ! run_fwconsole reload >>"$MODULE_UNINSTALL_LOG" 2>&1; then
    PBX_RELOAD_WARNING=1
    log "SLS removal was verified, but the PBX integration reload failed. Review $MODULE_UNINSTALL_LOG_PATH, resolve the PBX reload error, and run fwconsole reload."
  fi
}

capture_configured_ami_username() {
  CONFIGURED_AMI_USERNAME="$(SLS_CONFIG="$DATA_DIR/mass-notifications.config" SLS_UNINSTALL_CONFIG_DECODER="$RECOVERY_CONFIG_DECODER" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 <<'PHPAMI'
<?php
$path = (string)getenv('SLS_CONFIG');
if ($path === '' || (!file_exists($path) && !is_link($path))) { exit(0); }
$metadata = lstat($path);
if (is_link($path) || !is_file($path) || !is_array($metadata) || $metadata['nlink'] !== 1 || $metadata['size'] > 3 * 1024 * 1024) {
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
echo $username;
PHPAMI
)" || return 1
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
        if info.st_uid not in (0, account.pw_uid) or info.st_mode & 0o002 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX):
            raise SystemExit('Retained SLS data has an unsafe owner or writable parent; no data was deleted')
finally: os.close(fd)
PYRETAIN
}

preserve_user_data() {
  [ "$PURGE_CONFIG" != "1" ] || return 0
  CONFIG_TMP="$(mktemp -d /tmp/slsmassnotifyserver-config.XXXXXX)"
  chmod 0700 "$CONFIG_TMP"

  # DATA_DIR is service-writable while this script runs as root. Anchor every
  # read to no-follow directory descriptors and reject links/special files so
  # preservation cannot disclose a root-only file or restore an unsafe object.
  if ! /usr/bin/python3 - "$DATA_DIR" "$CONFIG_TMP" <<'PY'
import errno
import os
import stat
import sys

source_root, target_root = sys.argv[1:3]
no_follow = getattr(os, "O_NOFOLLOW", 0)
read_flags = os.O_RDONLY | os.O_CLOEXEC | no_follow
directory_flags = read_flags | os.O_DIRECTORY

try:
    source_fd = os.open(source_root, directory_flags)
except FileNotFoundError:
    raise SystemExit(0)
except OSError as exc:
    raise SystemExit(f"refusing unsafe preserved-data directory: {exc}")

target_fd = os.open(target_root, directory_flags)


def open_optional(parent_fd, name, flags):
    try:
        return os.open(name, flags, dir_fd=parent_fd)
    except FileNotFoundError:
        return None


def copy_regular(source_parent_fd, target_parent_fd, name):
    source_file_fd = open_optional(source_parent_fd, name, read_flags)
    if source_file_fd is None:
        return
    try:
        if not stat.S_ISREG(os.fstat(source_file_fd).st_mode):
            raise RuntimeError(f"refusing non-regular preserved file: {name}")
        target_file_fd = os.open(
            name,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | no_follow,
            0o600,
            dir_fd=target_parent_fd,
        )
        try:
            while True:
                chunk = os.read(source_file_fd, 1024 * 1024)
                if not chunk:
                    break
                view = memoryview(chunk)
                while view:
                    written = os.write(target_file_fd, view)
                    if written <= 0:
                        raise OSError(errno.EIO, "short write while preserving user data")
                    view = view[written:]
            os.fsync(target_file_fd)
        finally:
            os.close(target_file_fd)
    finally:
        os.close(source_file_fd)


def copy_tree(source_parent_fd, target_parent_fd, name):
    source_directory_fd = open_optional(source_parent_fd, name, directory_flags)
    if source_directory_fd is None:
        return
    try:
        os.mkdir(name, 0o700, dir_fd=target_parent_fd)
        target_directory_fd = os.open(name, directory_flags, dir_fd=target_parent_fd)
        try:
            for entry in sorted(os.listdir(source_directory_fd)):
                if entry in {"", ".", ".."} or "/" in entry or "\x00" in entry:
                    raise RuntimeError("refusing invalid preserved-data entry")
                entry_stat = os.stat(entry, dir_fd=source_directory_fd, follow_symlinks=False)
                if stat.S_ISLNK(entry_stat.st_mode):
                    raise RuntimeError(f"refusing symbolic link in preserved data: {entry}")
                if stat.S_ISDIR(entry_stat.st_mode):
                    copy_tree(source_directory_fd, target_directory_fd, entry)
                elif stat.S_ISREG(entry_stat.st_mode):
                    copy_regular(source_directory_fd, target_directory_fd, entry)
                else:
                    raise RuntimeError(f"refusing special file in preserved data: {entry}")
        finally:
            os.close(target_directory_fd)
    finally:
        os.close(source_directory_fd)


try:
    for filename in (
        "mass-notifications.config",
        "mass-notifications.pending.config",
        "schedule-executions.json",
    ):
        copy_regular(source_fd, target_fd, filename)
    copy_tree(source_fd, target_fd, "config-backups")
    sounds_fd = open_optional(source_fd, "sounds", directory_flags)
    if sounds_fd is not None:
        try:
            os.mkdir("sounds", 0o700, dir_fd=target_fd)
            target_sounds_fd = os.open("sounds", directory_flags, dir_fd=target_fd)
            try:
                copy_tree(sounds_fd, target_sounds_fd, "tones")
            finally:
                os.close(target_sounds_fd)
        finally:
            os.close(sounds_fd)
finally:
    os.close(target_fd)
    os.close(source_fd)
PY
  then
    log "Refusing to preserve unsafe configuration or uploaded-tone data. No uninstall changes were made."
    return 1
  fi
}

restore_user_data() {
  [ -n "$CONFIG_TMP" ] && [ -d "$CONFIG_TMP" ] || return 0
  if find "$CONFIG_TMP" -type f -print -quit | grep -q .; then
    if ! /usr/bin/python3 - "$CONFIG_TMP" "$DATA_DIR" <<'PY'
import errno
import os
import pwd
import secrets
import stat
import sys

source_root, destination_root = sys.argv[1:3]
no_follow = getattr(os, "O_NOFOLLOW", 0)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | no_follow
read_flags = os.O_RDONLY | os.O_CLOEXEC | no_follow
write_flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | no_follow
account = pwd.getpwnam("asterisk")
file_count = 0
byte_count = 0


def open_parent(path):
    parts = [part for part in path.split("/") if part]
    if not path.startswith("/") or not parts or "\x00" in path:
        raise RuntimeError("invalid restore path")
    parent_fd = os.open("/", directory_flags)
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    return parent_fd, parts[-1]


def open_or_create_directory(parent_fd, name):
    try:
        os.mkdir(name, 0o700, dir_fd=parent_fd)
    except FileExistsError:
        pass
    directory_fd = os.open(name, directory_flags, dir_fd=parent_fd)
    if not stat.S_ISDIR(os.fstat(directory_fd).st_mode):
        os.close(directory_fd)
        raise RuntimeError("restore destination is not a directory")
    return directory_fd


def restore_regular(source_parent_fd, destination_parent_fd, name, relative_path):
    global file_count, byte_count
    source_fd = os.open(name, read_flags, dir_fd=source_parent_fd)
    if not stat.S_ISREG(os.fstat(source_fd).st_mode):
        os.close(source_fd)
        raise RuntimeError(f"refusing non-regular restore source: {relative_path}")
    temporary_name = ".sls-restore-" + secrets.token_hex(8)
    temporary_fd = os.open(temporary_name, write_flags, 0o600, dir_fd=destination_parent_fd)
    try:
        while True:
            chunk = os.read(source_fd, 1024 * 1024)
            if not chunk:
                break
            byte_count += len(chunk)
            if byte_count > 256 * 1024 * 1024:
                raise RuntimeError("preserved data exceeds the restore size limit")
            view = memoryview(chunk)
            while view:
                written = os.write(temporary_fd, view)
                if written <= 0:
                    raise OSError(errno.EIO, "short write while restoring user data")
                view = view[written:]
        file_count += 1
        if file_count > 2000:
            raise RuntimeError("preserved data exceeds the restore file limit")
        mode = 0o644 if relative_path.startswith("sounds/tones/") else 0o640
        os.fchmod(temporary_fd, mode)
        os.fchown(temporary_fd, account.pw_uid, account.pw_gid)
        os.fsync(temporary_fd)
        os.close(temporary_fd)
        temporary_fd = -1
        os.replace(temporary_name, name, src_dir_fd=destination_parent_fd, dst_dir_fd=destination_parent_fd)
    finally:
        if temporary_fd >= 0:
            os.close(temporary_fd)
        os.close(source_fd)
        try:
            os.unlink(temporary_name, dir_fd=destination_parent_fd)
        except FileNotFoundError:
            pass


def restore_tree(source_fd, destination_fd, prefix=""):
    for name in sorted(os.listdir(source_fd)):
        if name in {"", ".", ".."} or "/" in name or "\x00" in name:
            raise RuntimeError("invalid preserved-data name")
        relative_path = f"{prefix}/{name}" if prefix else name
        metadata = os.stat(name, dir_fd=source_fd, follow_symlinks=False)
        if stat.S_ISLNK(metadata.st_mode):
            raise RuntimeError(f"refusing symbolic link in preserved data: {relative_path}")
        if stat.S_ISDIR(metadata.st_mode):
            source_child_fd = os.open(name, directory_flags, dir_fd=source_fd)
            destination_child_fd = open_or_create_directory(destination_fd, name)
            try:
                os.fchmod(destination_child_fd, 0o700)
                os.fchown(destination_child_fd, 0, 0)
                restore_tree(source_child_fd, destination_child_fd, relative_path)
                mode = 0o755 if relative_path in {"sounds", "sounds/tones"} else 0o750
                os.fchown(destination_child_fd, account.pw_uid, account.pw_gid)
                os.fchmod(destination_child_fd, mode)
                os.fsync(destination_child_fd)
            finally:
                os.close(destination_child_fd)
                os.close(source_child_fd)
        elif stat.S_ISREG(metadata.st_mode):
            restore_regular(source_fd, destination_fd, name, relative_path)
        else:
            raise RuntimeError(f"refusing special file in preserved data: {relative_path}")


source_fd = os.open(source_root, directory_flags)
destination_parent_fd, destination_name = open_parent(destination_root)
try:
    destination_fd = open_or_create_directory(destination_parent_fd, destination_name)
    try:
        os.fchmod(destination_fd, 0o700)
        os.fchown(destination_fd, 0, 0)
        restore_tree(source_fd, destination_fd)
        os.fchown(destination_fd, account.pw_uid, account.pw_gid)
        os.fchmod(destination_fd, 0o750)
        os.fsync(destination_fd)
        os.fsync(destination_parent_fd)
    finally:
        os.close(destination_fd)
finally:
    os.close(destination_parent_fd)
    os.close(source_fd)
PY
    then
      log "Unable to restore preserved user data without following symbolic links. The root-owned recovery copy remains at $CONFIG_TMP."
      return 1
    fi
  fi
  rm -rf "$CONFIG_TMP"
  CONFIG_TMP=""
}

restore_user_data_on_exit() {
  local status=$?
  trap - EXIT
  if [ -n "$CONFIG_TMP" ] && [ -d "$CONFIG_TMP" ]; then
    log "Uninstall did not complete; restoring the preserved central configuration and user audio."
    restore_user_data || true
  fi
  cleanup_recovery_signer
  exit "$status"
}

remove_menu_patch() {
  local path="/var/www/html/admin/views/menu_items.php"
  [ -w "$path" ] || return 0
  /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I - "$path" <<'PY'
import re
import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8", errors="ignore") as handle:
    data = handle.read()

data = re.sub(
    r"\t// SLS Mass Notifications menu placement:.*?(?=\telse if \(\$a == 'other'\)\n\t\treturn (?:1|true);\n)",
    "",
    data,
    flags=re.S,
)
for label in ("mass notifications", "mass notify"):
    for block in (
        f"\telse if ($a == '{label}' && $b == 'other')\n\t\treturn -1;\n",
        f"\telse if ($a == 'other' && $b == '{label}')\n\t\treturn 1;\n",
        f"\telse if ($a == '{label}' && $b == 'user panel')\n\t\treturn 1;\n",
        f"\telse if ($a == 'user panel' && $b == '{label}')\n\t\treturn -1;\n",
        f"\telse if ($a == '{label}')\n\t\treturn 1;\n",
        f"\telse if ($b == '{label}')\n\t\treturn -1;\n",
    ):
        data = data.replace(block, "")

with open(path, "w", encoding="utf-8") as handle:
    handle.write(data)
PY
}

remove_legacy_dashboard_patch() {
  local path="/var/www/html/admin/modules/dashboard/sections/Overview.class.php"
  [ -w "$path" ] || return 0
  /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I - "$path" <<'PY'
import re
import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8", errors="ignore") as handle:
    data = handle.read()
data = re.sub(
    r"\n\s*\$final\[\$i\]\s*=\s*\$this->checkSlsMassNotify\(\);\s*"
    r"\$final\[\$i\]\['title'\]\s*=\s*_\(\"Mass Notifications (?:Plugin|Module)\"\);\s*\$i\+\+;\s*",
    "\n",
    data,
    flags=re.S,
)
data = re.sub(
    r"\n\s*private function checkSlsMassNotify\(\)\s*\{.*?"
    r"(?=\n\s*private function genAlertGlyphicon\()",
    "\n",
    data,
    flags=re.S,
)
with open(path, "w", encoding="utf-8") as handle:
    handle.write(data)
PY
}

remove_managed_block() {
  local path="$1"
  local name="$2"
  [ -f "$path" ] || return 0
  /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I - "$path" "$name" <<'PY'
import re
import sys

path, name = sys.argv[1], sys.argv[2]
with open(path, "r", encoding="utf-8", errors="ignore") as handle:
    data = handle.read()
for prefix in (";", "#"):
    start = re.escape(prefix + " BEGIN " + name)
    end = re.escape(prefix + " END " + name)
    data = re.sub(r"\n?" + start + r".*?" + end + r"\n?", "\n", data, flags=re.S)
legacy_start = re.escape(";-- BEGIN " + name + " --")
legacy_end = re.escape(";-- END " + name + " --")
data = re.sub(r"\n?" + legacy_start + r".*?" + legacy_end + r"\n?", "\n", data, flags=re.S)
with open(path, "w", encoding="utf-8") as handle:
    handle.write(data.strip() + ("\n" if data.strip() else ""))
PY
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
  current="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=UnitFileState --value sls-mass-notify-phone-events.service)" || return 1
  if [ "$enabled" = enabled ] && [ "$current" = disabled ]; then
    /usr/bin/timeout --kill-after=2 20 systemctl enable sls-mass-notify-phone-events.service || {
      log 'Uninstall stopped before removing module files, but the collector enablement could not be restored. Preserve its unit/history and restore its previous enabled state after reviewing systemd.'
      return 1
    }
  elif [ "$current" != "$enabled" ]; then
    log 'Collector enablement changed unexpectedly; no further service state was adopted. Preserve the unit and review systemd before retrying.'
    return 1
  fi
  [ "$previous" = active ] || return 0
  if ! /usr/bin/timeout --kill-after=2 20 systemctl start sls-mass-notify-phone-events.service \
    || [ "$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" != active ]; then
    log 'Uninstall stopped before removing module files, but the previously active collector could not be restored. Preserve its service definition and history; review systemctl status/journalctl and restart the approved collector after resolving the named fault.'
    return 1
  fi
  log 'Uninstall stopped before removing module files; the previously active collector was restored without changing its enablement.'
}

remove_phone_collector_service() {
  local unit="/etc/systemd/system/sls-mass-notify-phone-events.service" active_state previous_state fragment dropins enabled_state
  [ -e "$unit" ] || [ -L "$unit" ] || return 0
  verify_uninstall_collector_unit "$unit" || return 1
  previous_state="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" || return 1
  fragment="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=FragmentPath --value sls-mass-notify-phone-events.service)" || return 1
  dropins="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=DropInPaths --value sls-mass-notify-phone-events.service)" || return 1
  enabled_state="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=UnitFileState --value sls-mass-notify-phone-events.service)" || return 1
  case "$previous_state:$enabled_state" in active:enabled|active:disabled|inactive:enabled|inactive:disabled|failed:enabled|failed:disabled) ;; *) log 'Collector enablement or process state is unsupported. No service was changed; review masks or custom supervision before retrying.'; return 1 ;; esac
  if [ "$fragment" != "$unit" ] || [ -n "$dropins" ]; then
    log 'Collector systemd fragments or overrides differ from the signed service. No service was changed; preserve custom supervision for review.'
    return 1
  fi
  if ! /usr/bin/timeout --kill-after=2 20 systemctl stop sls-mass-notify-phone-events.service; then
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  active_state="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" || {
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
  if ! /usr/bin/timeout --kill-after=2 20 systemctl disable sls-mass-notify-phone-events.service; then
    log 'The collector stopped but its enablement could not be removed. Its service and module files remain; review systemd and retry.'
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  if ! rm -- "$unit"; then
    restore_uninstall_collector "$unit" "$previous_state" "$enabled_state" || true
    return 1
  fi
  /usr/bin/timeout --kill-after=2 10 systemctl daemon-reload || {
    log 'The collector service was removed but systemd reload failed. Module and history remain; preserve signed recovery evidence, resolve systemd and retry the included uninstaller.'
    return 1
  }
}

disable_apache_conf() {
  rm -f /etc/logrotate.d/sls-mass-notify
  command -v a2disconf >/dev/null && a2disconf sls-mass-notify >/dev/null 2>&1 || true
  rm -f /etc/apache2/conf-enabled/sls-mass-notify.conf
  rm -f /etc/apache2/conf-available/sls-mass-notify.conf
  rm -f /var/lib/apache2/conf/enabled_by_admin/sls-mass-notify
	  rm -f /var/lib/apache2/conf/disabled_by_admin/sls-mass-notify
  systemctl reload apache2 >/dev/null 2>&1 || true
}

remove_freepbx_manager_users() {
  SLS_CONFIGURED_AMI_USERNAME="$CONFIGURED_AMI_USERNAME" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$candidates = ['slsmassnotify', 'sls_mass_notify', 'nws_push'];
$configured = (string)getenv('SLS_CONFIGURED_AMI_USERNAME');
if ($configured !== '') { $candidates[] = $configured; }
$candidates = array_values(array_unique(array_filter($candidates)));
try {
    $manager = \FreePBX::Manager();
    foreach ($candidates as $username) {
        if ($manager->isExist_manager($username, true)) {
            $manager->del_manager($username, true);
        }
    }
} catch (Throwable $managerError) {
    $database = \FreePBX::Database();
    $statement = $database->prepare('DELETE FROM manager WHERE name = ?');
    foreach ($candidates as $username) {
        $statement->execute([$username]);
    }
}
exit(0);
PHP
  rm -f /etc/asterisk/slsmassnotify
}

remove_bundled_system_recordings() {
  SLS_DATA_DIR="$DATA_DIR" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 <<'PHP'
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
  SLS_CONFIGURED_AMI_USERNAME="$CONFIGURED_AMI_USERNAME" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$candidates = ['slsmassnotify', 'sls_mass_notify', 'nws_push'];
$configured = (string)getenv('SLS_CONFIGURED_AMI_USERNAME');
if ($configured !== '') { $candidates[] = $configured; }
$candidates = array_values(array_unique(array_filter($candidates)));
$database = \FreePBX::Database();
$statement = $database->prepare('SELECT COUNT(*) FROM manager WHERE name = ?');
foreach ($candidates as $username) {
    $statement->execute([$username]);
    if ((int)$statement->fetchColumn() > 0) {
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
    /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin \
    /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin \
    /var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php \
    /var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php; do
    if [ -e "$artifact" ] || [ -L "$artifact" ]; then
      log "Uninstall verification failed; managed artifact remains: $artifact"
      return 1
    fi
  done
  for managed_file in \
    /etc/asterisk/sip_notify_custom.conf \
    /etc/asterisk/extensions_custom.conf \
    /etc/asterisk/manager_custom.conf; do
    if grep -Fq 'SLS Mass Notifications' "$managed_file" 2>/dev/null; then
      log "Uninstall verification failed; a managed Asterisk block remains in $managed_file."
      return 1
    fi
  done
  verify_removed_cron || return 1
  if grep -Fq 'SLS Mass Notifications menu placement:' /var/www/html/admin/views/menu_items.php 2>/dev/null; then
    log "Uninstall verification failed; the managed FreePBX menu placement remains."
    return 1
  fi
  return 0
}

verify_stock_module() {
  local stock_module="$1"
  SLS_VERIFY_MODULE="$stock_module" /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 -r '
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
  local module_dir="/var/www/html/admin/modules/$stock_module"
  local workdir
  local keyid
  local fingerprint
  local freepbx_gpg_home
  local signedby

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
  local wrapper="/usr/local/bin/piper"
  local legacy_piper_bin="$DATA_DIR/piper/venv/bin/piper"
  local piper_bin="/usr/local/bin/sls_mass_notify/piper/venv/bin/piper"
  if [ -L "$wrapper" ] && { [ "$(readlink "$wrapper")" = "$piper_bin" ] || [ "$(readlink "$wrapper")" = "$legacy_piper_bin" ]; }; then
    rm -f "$wrapper"
  elif [ -f "$wrapper" ] && grep -Eq "SLS_Mass_Notifications_Plugin/piper|sls_mass_notify/piper" "$wrapper" 2>/dev/null; then
    rm -f "$wrapper"
  fi
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
  rm -f /usr/local/sbin/sign_sls_mass_notify_local_sig.sh.bak-*
  rm -f /etc/freepbx.secure/slsmassnotifyserver.sig
  remove_managed_sound_link /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin
  remove_managed_sound_link /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin
  rm -f /var/lib/asterisk/bin/sls_mass_notify
  rm -f /var/lib/asterisk/bin/sls_mass_notify_test.sh
  rm -rf /var/www/html/api/sls-mass-notify
  rm -rf /var/www/html/api/sipnotify
  rm -rf /var/www/html/mass-notify
  rm -rf /var/www/html/sls_mass_notify
  rm -rf /etc/asterisk/slsmassnotify
  if [ "$PURGE_CONFIG" = 1 ]; then
    rm -rf "$DATA_DIR"
    rm -f /var/log/sls_mass_notify.log
    rm -f /var/log/sls_mass_notify_events.jsonl
    rm -f /var/log/sls_mass_notify_push.log
  fi
  rm -f /tmp/slsmassnotifyserver-*.tgz
  rm -f /tmp/slsmassnotifyserver-install.log
  rm -f /tmp/sls-install.sh /tmp/sls-install.sh.bak-*
  rm -f /tmp/sls-uninstall.sh /tmp/sls-uninstall.sh.bak-*
  rm -rf /tmp/sls-mass-notify-*
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
  local user work
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
      log "The $user SLS cron entries could not be removed. Original crontabs were retained in $work; review cron permissions and retry."
      return 1
    fi
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
  /usr/sbin/runuser -u asterisk -- /usr/bin/php -d pcre.jit=0 <<'PHP'
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
      user="root"
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
reference = json.loads(read(root / 'slsmassnotifyserver.active.json', 1024))
if not isinstance(reference, dict): raise SystemExit('Invalid protected SLS publisher reference')
digest = reference.get('sha256', '')
if not isinstance(digest, str) or not re.fullmatch('[a-f0-9]{64}', digest) or reference.get('generation') != 'slsmassnotifyserver-' + digest:
    raise SystemExit('Invalid protected SLS publisher generation')
generation = root / reference['generation']
raw = read(generation / 'inventory.json', 8 * 1024 * 1024)
if hashlib.sha256(raw).hexdigest() != digest: raise SystemExit('Protected SLS publisher inventory changed')
inventory = json.loads(raw)
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
  # The complete root runtime was publisher-verified before teardown. Remove
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
  trap restore_user_data_on_exit EXIT
  snapshot_local_signer
  [ -n "$RECOVERY_SIGNER" ] || { log "An approved-inventory signer is required before uninstall can preserve the reviewed FreePBX modules."; return 1; }
  verify_uninstall_inventories
  verify_uninstall_cron_access
  capture_signing_fingerprint
  validate_retained_user_data
  capture_pbx_running_state
  acquire_uninstall_workers
  inspect_uninstall_idle
  capture_configured_ami_username
  assert_uninstall_workers
  remove_phone_collector_service
	  assert_uninstall_workers
	  remove_freepbx_manager_users
	  assert_uninstall_workers
	  remove_module_registration
  assert_uninstall_workers
  remove_bundled_system_recordings
  remove_cron
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
  remove_runtime_files
  restore_user_data
  remove_trusted_signing_key
  verify_freepbx_cleanup
  reload_removed_pbx_integration
  remove_freepbx_manager_users
  if [ "$PBX_RUNNING" = 1 ]; then
    asterisk -rx "manager reload" >/dev/null 2>&1 || true
  fi
  verify_freepbx_cleanup
  assert_uninstall_workers
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
