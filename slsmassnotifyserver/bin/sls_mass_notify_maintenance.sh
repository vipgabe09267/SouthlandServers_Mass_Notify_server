#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -euo pipefail

umask 027
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/repair.request"
TIMEZONE_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/timezone.request"
UPDATE_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update.request"
UPDATE_PROGRESS_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-progress.json"
MAINTENANCE_PROGRESS_FILE="/run/asterisk/sls-mass-notify-maintenance-progress.json"
CONFIG_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config"
UNINSTALL_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/uninstall.request"
INSTALL_FAILURE_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/install-failure.json"
INTEGRATION_REPAIR_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/integration-repair-required.json"
RUNTIME_DIR="/usr/local/bin/sls_mass_notify"
SIGNER="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
PRIVILEGED_HELPER="$RUNTIME_DIR/sls_privileged_install.py"
LOG_FILE="/var/log/sls_mass_notify.log"
LOCK_FILE="/run/lock/sls-mass-notify-maintenance.lock"
MODULE_DIR="/var/www/html/admin/modules/slsmassnotifyserver"
DASHBOARD_DIR="/var/www/html/admin/modules/dashboard"
MENU_FILE="/var/www/html/admin/views/menu_items.php"
ACTIVE_ACTION=""
FAILURE_RECORDED=0
LOG_FD=""

# Open only a root-owned regular file through trusted directories. Creation is
# exclusive; an existing file is never truncated or written before fd identity
# verification. Sticky root-owned /tmp and /run/lock are permitted.
open_root_owned_file() {
  local output_variable="$1" protected_path="$2"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 -I - "$protected_path" <<'PY'
import os
import stat
import sys

path = sys.argv[1]
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
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_nlink != 1 or metadata.st_mode & 0o022:
            raise SystemExit(1)
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

# Pin the shared maintenance log before any privileged append. The service
# account owns historical installations of this file, but cannot replace its
# directory entry under the root-owned /var/log parent.
open_maintenance_log() {
  local output_variable="$1" protected_path="$2"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 -I - "$protected_path" <<'PYLOG'
import os
import pwd
import stat
import sys

path = sys.argv[1]
parts = path.split('/')[1:]
if not path.startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
    raise SystemExit(1)
account = pwd.getpwnam('asterisk')
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW
parent = os.open('/', directory_flags)
descriptor = -1
try:
    for component in parts[:-1]:
        child = os.open(component, directory_flags, dir_fd=parent)
        os.close(parent); parent = child
        metadata = os.fstat(parent)
        if (metadata.st_uid != 0
                or metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX):
            raise RuntimeError('unsafe log parent')
    flags = os.O_WRONLY | os.O_APPEND | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    try:
        descriptor = os.open(parts[-1], flags | os.O_CREAT | os.O_EXCL, 0o640, dir_fd=parent)
        os.fchown(descriptor, account.pw_uid, account.pw_gid)
        os.fchmod(descriptor, 0o640)
        os.fsync(parent)
    except FileExistsError:
        descriptor = os.open(parts[-1], flags, dir_fd=parent)
    opened = os.fstat(descriptor)
    current = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    allowed_owner = (opened.st_uid, opened.st_gid) in (
        (account.pw_uid, account.pw_gid), (0, account.pw_gid), (0, 0))
    if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1 or not allowed_owner
            or opened.st_mode & 0o022
            or (opened.st_dev, opened.st_ino, opened.st_uid, opened.st_gid,
                opened.st_mode, opened.st_nlink)
            != (current.st_dev, current.st_ino, current.st_uid, current.st_gid,
                current.st_mode, current.st_nlink)):
        raise RuntimeError('unsafe maintenance log')
    print(f'{opened.st_dev}:{opened.st_ino}')
finally:
    if descriptor >= 0: os.close(descriptor)
    os.close(parent)
PYLOG
)" || return 1
  exec {protected_fd}>>"$protected_path" || return 1
  opened_identity="$(stat -Lc '%d:%i' "/proc/${BASHPID}/fd/$protected_fd" 2>/dev/null)" || opened_identity=""
  if [ "$opened_identity" != "$protected_identity" ]; then
    exec {protected_fd}>&-
    return 1
  fi
  printf -v "$output_variable" '%s' "$protected_fd"
}

log() {
  [ -n "${LOG_FD:-}" ] || return 0
  printf '%s: %s\n' "$(date)" "$*" >&"$LOG_FD" || true
}

close_inherited_maintenance_lock_fds() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-$LOCK_FILE}"
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

# The parent maintenance shell keeps the lock. Child processes that can leave
# background helpers behind receive no duplicate descriptor, preventing a GPG
# key refresh from keeping repair/update/uninstall blocked after this run exits.
run_without_maintenance_lock() (
  close_inherited_maintenance_lock_fds
  if [ -n "${MUTATION_KEEPALIVE_FD:-}" ]; then exec {MUTATION_KEEPALIVE_FD}>&-; fi
  "$@"
)

# Admit the protected helper before importing or executing any runtime Python.
# The helper then verifies all root-executable SLS bytes against the enrolled
# publisher generation. Local signature status never authorizes execution.
admit_root_runtime() {
  /usr/bin/python3 -I - "$PRIVILEGED_HELPER" <<'PYGUARD' || return 1
import os
from pathlib import Path
import stat
import sys
path = Path(sys.argv[1])
for file in (path, path.with_name('sls_module_trust.py')):
    for item in [file] + list(file.parents)[:-1]:
        info = item.lstat()
        if stat.S_ISLNK(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise SystemExit('Untrusted SLS protected runtime path: ' + str(item))
    info = file.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise SystemExit('Unsafe SLS protected helper file')
PYGUARD
  run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=5 120 \
    /usr/bin/python3 -I "$PRIVILEGED_HELPER" admit
}

verify_installed_sls_module() {
  run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=5 120 \
    /usr/bin/python3 -I "$RUNTIME_DIR/sls_module_trust.py" \
    --root /var/lib/sls-mass-notify-trust check \
    --module slsmassnotifyserver --web-root /var/www/html
}

# A dedicated authenticated child owns the worker and settings locks. Keep
# service/PHP children from retaining its lifetime pipe after maintenance exits.
acquire_mutation_coordination() {
  MUTATION_STARTED=0
  MUTATION_VERIFIED=0
  MUTATION_BASELINE_DIR="$(mktemp -d /run/sls-maintenance-idle.XXXXXXXX)" || return 1
  chmod 0700 "$MUTATION_BASELINE_DIR" || { release_mutation_coordination; return 1; }
  coproc SLS_MAINTENANCE_GUARD {
    /usr/bin/python3 -I "$RUNTIME_DIR/sls_install_guard.py" --data "${CONFIG_FILE%/*}" --settings hold
  }
  MUTATION_GUARD_PID="$SLS_MAINTENANCE_GUARD_PID"
  local status guard_read_fd="${SLS_MAINTENANCE_GUARD[0]}" guard_write_fd="${SLS_MAINTENANCE_GUARD[1]}"
  if ! exec {MUTATION_KEEPALIVE_FD}>&"$guard_write_fd"; then
    exec {guard_read_fd}<&- {guard_write_fd}>&-
    release_mutation_coordination
    return 1
  fi
  # The duplicate above is the sole parent-side keepalive. Leaving Bash's
  # original coprocess writer open prevents EOF and can deadlock release/wait.
  exec {guard_write_fd}>&-
  if ! IFS= read -r -t 125 status <&"$guard_read_fd" || [ "$status" != ready ]; then
    exec {guard_read_fd}<&-
    release_mutation_coordination
    return 1
  fi
  exec {guard_read_fd}<&-
  if ! run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=5 120 \
    /usr/bin/python3 -I "$RUNTIME_DIR/sls_install_idle.py" --data "${CONFIG_FILE%/*}" \
    inspect --repair-collector >"$MUTATION_BASELINE_DIR/before.json"; then
    release_mutation_coordination
    return 1
  fi
  chmod 0600 "$MUTATION_BASELINE_DIR/before.json" || { release_mutation_coordination; return 1; }
  MUTATION_CONFIG_BEFORE="$(mutation_config_fingerprint)" || { release_mutation_coordination; return 1; }
  MUTATION_STARTED=1
}

mutation_config_fingerprint() {
  /usr/bin/python3 -I - "${CONFIG_FILE%/*}" <<'PYCONFIG'
import hashlib, json, os, pwd, stat, sys
account = pwd.getpwnam('asterisk')
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', flags)
try:
    for part in sys.argv[1].split('/'):
        if not part: continue
        if part in ('.', '..'): raise RuntimeError('Unsafe settings directory')
        child = os.open(part, flags, dir_fd=parent)
        os.close(parent); parent = child
        info = os.fstat(parent)
        if info.st_uid not in (0, account.pw_uid) or info.st_gid not in (0, account.pw_gid) or (info.st_mode & 0o022 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX)):
            raise RuntimeError('Unsafe settings ancestor')
    result = {}
    for name in ('mass-notifications.config', 'mass-notifications.pending.config'):
        try:
            fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        except FileNotFoundError:
            result[name] = None
            continue
        try:
            before = os.fstat(fd)
            if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                    or before.st_uid not in (0, account.pw_uid)
                    or before.st_gid not in (0, account.pw_gid)
                    or before.st_mode & 0o022 or before.st_size > 16777216):
                raise RuntimeError('Unsafe or oversized settings file')
            digest = hashlib.sha256(); size = 0
            while True:
                chunk = os.read(fd, 65536)
                if not chunk: break
                size += len(chunk)
                if size > 16777216: raise RuntimeError('Settings file exceeds size limit')
                digest.update(chunk)
            after = os.fstat(fd); current = os.stat(name, dir_fd=parent, follow_symlinks=False)
            identity = lambda item: (item.st_dev, item.st_ino, item.st_uid, item.st_gid,
                                     item.st_mode, item.st_size, item.st_mtime_ns,
                                     item.st_ctime_ns, item.st_nlink)
            if identity(before) != identity(after) or identity(after) != identity(current):
                raise RuntimeError('Settings file changed during inspection')
            result[name] = digest.hexdigest()
        finally: os.close(fd)
    print(json.dumps(result, sort_keys=True))
finally: os.close(parent)
PYCONFIG
}

verify_mutation_idle() {
  [ -n "${MUTATION_GUARD_PID:-}" ] && kill -0 "$MUTATION_GUARD_PID" 2>/dev/null || return 1
  local after
  after="$(mutation_config_fingerprint)" || return 1
  [ "$after" = "$MUTATION_CONFIG_BEFORE" ] || {
    log "Maintenance changed active or pending settings unexpectedly; preserve evidence and do not rewind settings."
    return 1
  }
  run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=5 120 \
    /usr/bin/python3 -I "$RUNTIME_DIR/sls_install_idle.py" --data "${CONFIG_FILE%/*}" \
    inspect --baseline "$MUTATION_BASELINE_DIR/before.json" || return 1
  MUTATION_VERIFIED=1
}

release_mutation_coordination() {
  local guard_pid="${MUTATION_GUARD_PID:-}"
  if [ -n "${MUTATION_KEEPALIVE_FD:-}" ]; then
    (printf 'release\n' >&"$MUTATION_KEEPALIVE_FD") 2>/dev/null || true
    exec {MUTATION_KEEPALIVE_FD}>&-
    MUTATION_KEEPALIVE_FD=""
  fi
  if [ -n "$guard_pid" ]; then
    for _ in {1..100}; do
      kill -0 "$guard_pid" 2>/dev/null || break
      /usr/bin/sleep 0.05
    done
    if kill -0 "$guard_pid" 2>/dev/null; then
      log "The maintenance coordination guard did not stop after release; terminating it."
      kill -TERM "$guard_pid" 2>/dev/null || true
      for _ in {1..40}; do
        kill -0 "$guard_pid" 2>/dev/null || break
        /usr/bin/sleep 0.05
      done
      kill -KILL "$guard_pid" 2>/dev/null || true
    fi
    wait "$guard_pid" 2>/dev/null || true
  fi
  MUTATION_GUARD_PID=""
  if [ -n "${MUTATION_BASELINE_DIR:-}" ]; then
    if [ "${MUTATION_STARTED:-0}" -eq 1 ] && [ "${MUTATION_VERIFIED:-0}" -ne 1 ]; then
      log "Preserved pre-maintenance idle evidence: $MUTATION_BASELINE_DIR/before.json"
    else
      rm -f -- "$MUTATION_BASELINE_DIR/before.json"
      rmdir -- "$MUTATION_BASELINE_DIR" 2>/dev/null || true
    fi
    MUTATION_BASELINE_DIR=""
  fi
}

write_status_json() {
  /usr/bin/python3 -I - "$@" <<'PY'
import json
import os
import pwd
import stat
import sys
from datetime import datetime, timezone

kind, path, first, second, third, category, status = sys.argv[1:]
now = datetime.now(timezone.utc).isoformat()
if kind == 'update':
    payload = {'state': first, 'message': second[:300], 'updated_at': now,
               'error_category': category, 'exit_code': int(status)}
elif kind == 'maintenance':
    payload = {'action': first, 'state': second, 'message': third[:300],
               'updated_at': now, 'error_category': category, 'exit_code': int(status)}
elif kind == 'install':
    payload = {'version': 1, 'failed_at': now, 'stage': first[:80],
               'message': 'SLS Mass Notify installation repair did not complete.',
               'solution': second[:400], 'log': '/var/log/sls_mass_notify.log'}
else:
    raise SystemExit('Unknown maintenance status type')
parts = path.split('/')[1:]
if not path.startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
    raise SystemExit('Unsafe maintenance status path')
account = pwd.getpwnam("asterisk")
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', flags)
temporary = None
try:
    for component in parts[:-1]:
        try:
            child = os.open(component, flags, dir_fd=parent)
        except FileNotFoundError:
            os.mkdir(component, 0o750, dir_fd=parent)
            child = os.open(component, flags, dir_fd=parent)
            os.fchown(child, account.pw_uid, account.pw_gid)
        metadata = os.fstat(child)
        sticky_root = metadata.st_uid == 0 and bool(metadata.st_mode & stat.S_ISVTX)
        if (not stat.S_ISDIR(metadata.st_mode)
                or metadata.st_uid not in (0, account.pw_uid)
                or metadata.st_gid not in (0, account.pw_gid)
                or (metadata.st_mode & 0o022 and not sticky_root)):
            os.close(child)
            raise SystemExit('Unsafe maintenance status parent')
        os.close(parent); parent = child
    try: existing = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError: existing = None
    if existing is not None and (not stat.S_ISREG(existing.st_mode) or existing.st_nlink != 1
                                 or existing.st_uid not in (0, account.pw_uid)
                                 or existing.st_gid not in (0, account.pw_gid)
                                 or existing.st_mode & 0o022):
        raise SystemExit('Unsafe maintenance status destination')
    temporary = '.sls-status-' + os.urandom(12).hex()
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
    with os.fdopen(fd, 'w', encoding='utf-8') as handle:
        json.dump(payload, handle, separators=(',', ':'))
        handle.write('\n'); handle.flush()
        os.fchown(handle.fileno(), account.pw_uid, account.pw_gid)
        os.fchmod(handle.fileno(), 0o640)
        os.fsync(handle.fileno())
    os.replace(temporary, parts[-1], src_dir_fd=parent, dst_dir_fd=parent)
    temporary = None
    os.fsync(parent)
finally:
    if temporary is not None:
        try:
            os.unlink(temporary, dir_fd=parent)
            os.fsync(parent)
        except FileNotFoundError: pass
    os.close(parent)
PY
}

write_update_progress() {
  write_status_json update "$UPDATE_PROGRESS_FILE" "$1" "$2" "" "${3:-}" "${4:-0}"
}

write_maintenance_progress() {
  write_status_json maintenance "$MAINTENANCE_PROGRESS_FILE" "$1" "$2" "$3" "${4:-}" "${5:-0}"
}

update_progress_is() {
  /usr/bin/python3 -I - "$UPDATE_PROGRESS_FILE" "$1" <<'PY'
import json
import os
import fcntl
import pwd
import stat
import sys
fd = -1
parent = -1
try:
    parts = sys.argv[1].split('/')[1:]
    if not sys.argv[1].startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
        raise ValueError('unsafe status path')
    account = pwd.getpwnam('asterisk')
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    parent = os.open('/', directory_flags)
    for part in parts[:-1]:
        child = os.open(part, directory_flags, dir_fd=parent)
        directory = os.fstat(child)
        sticky_root = directory.st_uid == 0 and bool(directory.st_mode & stat.S_ISVTX)
        if (not stat.S_ISDIR(directory.st_mode)
                or directory.st_uid not in (0, account.pw_uid)
                or directory.st_gid not in (0, account.pw_gid)
                or (directory.st_mode & 0o022 and not sticky_root)):
            os.close(child)
            raise ValueError('unsafe status parent')
        os.close(parent); parent = child
    fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
    before = os.fstat(fd)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_uid not in (0, account.pw_uid)
            or before.st_gid not in (0, account.pw_gid)
            or before.st_mode & 0o022 or before.st_size > 65536):
        raise ValueError('unsafe status file')
    fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
    with os.fdopen(fd, 'rb') as handle:
        fd = -1
        body = handle.read(65537)
        after = os.fstat(handle.fileno())
    if len(body) > 65536: raise ValueError('oversized status')
    current = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    identity = lambda item: (item.st_dev, item.st_ino, item.st_uid, item.st_gid,
                             item.st_mode, item.st_size, item.st_mtime_ns,
                             item.st_ctime_ns, item.st_nlink)
    if identity(before) != identity(after) or identity(after) != identity(current):
        raise ValueError('status changed during inspection')
    def unique_object(pairs):
        result = {}
        for key, value in pairs:
            if key in result: raise ValueError('duplicate JSON member')
            result[key] = value
        return result
    progress = json.loads(body, object_pairs_hook=unique_object,
                          parse_constant=lambda value: (_ for _ in ()).throw(ValueError('non-finite JSON number')))
    raise SystemExit(0 if isinstance(progress, dict) and progress.get('state') == sys.argv[2] else 1)
except (OSError, ValueError):
    raise SystemExit(1)
finally:
    if fd >= 0: os.close(fd)
    if parent >= 0: os.close(parent)
PY
}

fail_maintenance() {
  local category="$1" message="$2" status="${3:-1}"
  FAILURE_RECORDED=1
  log "Maintenance failed [$category] (exit $status): $message"
  if [ "$ACTIVE_ACTION" = "update" ]; then
    # Preserve the child's more specific sanitized category when available.
    update_progress_is failed || write_update_progress "failed" "$message" "$category" "$status" || true
  elif [ -n "$ACTIVE_ACTION" ]; then
    write_maintenance_progress "$ACTIVE_ACTION" "failed" "$message" "$category" "$status" || true
  fi
  exit "$status"
}

finish_maintenance() {
  local status=$?
  trap - EXIT
  release_mutation_coordination
  if [ "$status" -ne 0 ] && [ "$FAILURE_RECORDED" -ne 1 ] && [ -n "$ACTIVE_ACTION" ]; then
    fail_maintenance "process_failed" "The maintenance process stopped unexpectedly. Review Notification Logs for details." "$status"
  fi
  exit "$status"
}

write_install_failure() {
  local stage="$1"
  local solution="$2"
  write_status_json install "$INSTALL_FAILURE_FILE" "$stage" "$solution" "" "" 0
  /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=5 60 /usr/bin/php -r '
require "/etc/freepbx.conf";
\FreePBX::Notifications()->add_error(
    "slsmassnotifyserver",
    "INSTALLFAILED",
    "SLS Mass Notify installation repair failed",
    "The protected repair did not complete. " . $argv[1] . " Review /var/log/sls_mass_notify.log before retrying.",
    "",
    true,
    true
);
exit(0);
' "$solution" >/dev/null 2>&1 || true
}

clear_install_failure() {
  if [ ! -L "$INSTALL_FAILURE_FILE" ]; then
    rm -f "$INSTALL_FAILURE_FILE" 2>/dev/null || true
  fi
  /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=5 60 /usr/bin/php -r '
require "/etc/freepbx.conf";
\FreePBX::Notifications()->delete("slsmassnotifyserver", "INSTALLFAILED");
exit(0);
' >/dev/null 2>&1 || true
}

secure_central_config() {
  [ -e "$CONFIG_FILE" ] || [ -L "$CONFIG_FILE" ] || return 0
  if ! CONFIG_PATH="$CONFIG_FILE" /usr/bin/python3 -I - <<'PY'
import os
import pwd
import stat

path = os.environ["CONFIG_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path or ".." in parts:
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | os.O_NOFOLLOW
parent_fd = os.open("/", directory_flags)
config_fd = -1
account = pwd.getpwnam("asterisk")
def recognized(info):
    return (stat.S_ISREG(info.st_mode) and info.st_nlink == 1
            and info.st_uid in (0, account.pw_uid) and info.st_gid in (0, account.pw_gid)
            and stat.S_IMODE(info.st_mode) in (0o600, 0o640, 0o644) and info.st_size <= 3 * 1024 * 1024)
try:
    for index, component in enumerate(parts[:-1]):
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
        directory = os.fstat(parent_fd)
        sticky = index < len(parts) - 2 and directory.st_uid == 0 and directory.st_mode & stat.S_ISVTX
        if (directory.st_uid not in (0, account.pw_uid) or directory.st_gid not in (0, account.pw_gid)
                or directory.st_mode & 0o022 and not sticky):
            raise SystemExit('Central configuration parent ownership or permissions are unsafe; no configuration permissions were changed')
    before = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
    if not recognized(before):
        raise SystemExit('Central configuration owner, group, permissions or size are unrecognized; preserve the file and review it before retrying')
    config_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=parent_fd)
    metadata = os.fstat(config_fd)
    current = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
    if (not recognized(metadata)
            or (before.st_dev, before.st_ino, before.st_uid, before.st_gid, before.st_mode, before.st_size)
            != (metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid, metadata.st_mode, metadata.st_size)
            or (current.st_dev, current.st_ino, current.st_uid, current.st_gid, current.st_mode, current.st_size, current.st_nlink)
            != (metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid, metadata.st_mode, metadata.st_size, 1)):
        raise SystemExit(3)
    os.fchmod(config_fd, 0o640)
    os.fchown(config_fd, account.pw_uid, account.pw_gid)
    os.fsync(config_fd)
    verified = os.fstat(config_fd)
    current = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
    if (stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_uid != account.pw_uid
            or verified.st_gid != account.pw_gid or verified.st_nlink != 1
            or (current.st_dev, current.st_ino) != (verified.st_dev, verified.st_ino)):
        raise SystemExit(4)
    os.fsync(parent_fd)
finally:
    if config_fd >= 0:
        os.close(config_fd)
    os.close(parent_fd)
PY
  then
    log "Rejected unsafe protected central configuration path"
    return 1
  fi
}

[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1
open_maintenance_log LOG_FD "$LOG_FILE" || {
  printf '%s\n' 'SLS maintenance refused an unsafe log file.' >&2
  exit 1
}
trap finish_maintenance EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
MAINTENANCE_LOCK_FD=""
open_root_owned_file MAINTENANCE_LOCK_FD "$LOCK_FILE" || {
  log "The protected maintenance lock could not be opened safely."
  exit 1
}
flock -n "$MAINTENANCE_LOCK_FD" || exit 0
if ! admit_root_runtime >&"$LOG_FD" 2>&1; then
  ACTIVE_ACTION="trust"
  fail_maintenance "publisher_runtime_verification_failed" "The protected SLS runtime does not match an enrolled publisher release. Use the verified release installer to restore it; no automatic baseline was enrolled." 2
fi
if ! secure_central_config; then
  ACTIVE_ACTION="config"
  fail_maintenance "protected_config_validation_failed" "The protected central configuration could not be secured. Maintenance was stopped." 2
fi

# The protected daily journal avoids scanning configuration backups each minute.
if ! encryption_result="$(/usr/bin/timeout --signal=TERM --kill-after=5 120 \
  /usr/bin/python3 -I "$RUNTIME_DIR/sls_config_crypto.py" daily 2>&"$LOG_FD")"; then
  log "$encryption_result"
  ACTIVE_ACTION="config"
  fail_maintenance "configuration_encryption_failed" "SLS configuration encryption or annual key rotation could not be verified. Preserve the protected keyring and review the maintenance log before restoring settings." 2
fi
case "$encryption_result" in
  *'"rotated":true'*|*'"encrypted_files":'[1-9]*) log "$encryption_result" ;;
esac

# Reconciliation never sends an announcement. Probe worker bootstrap/storage as
# its service account, so a root-only success cannot mask delivery failures.
safe_single_regular_file() {
  local path="$1" links
  [ ! -L "$path" ] && [ -f "$path" ] || return 1
  links="$(stat -c '%h' -- "$path" 2>/dev/null)" || return 1
  [ "$links" = "1" ]
}

mark_integration_retry() {
  write_status_json maintenance "$INTEGRATION_REPAIR_FILE" "integration" "retry" \
    "Dashboard/menu integration signing must be retried." "integration_signing_failed" 1 \
    || log "Unable to record the required dashboard/menu signing retry."
}

clear_integration_retry() {
  if [ -e "$INTEGRATION_REPAIR_FILE" ] || [ -L "$INTEGRATION_REPAIR_FILE" ]; then
    safe_single_regular_file "$INTEGRATION_REPAIR_FILE" || return 1
    rm -f -- "$INTEGRATION_REPAIR_FILE"
  fi
}
announcement_worker="$RUNTIME_DIR/sls_mass_notify_announcement_worker.php"
if [ -r "$announcement_worker" ]; then
  run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=5 30 /usr/bin/php "$announcement_worker" --reconcile >&"$LOG_FD" 2>&1 \
    || log "Announcement job reconciliation failed; it will retry later."
  worker_probe="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/announcement-jobs/worker-probe.json"
  if safe_single_regular_file "$worker_probe"; then
    probe_modified="$(stat -c '%Y' -- "$worker_probe" 2>/dev/null || printf '0')"
  else
    probe_modified=0
  fi
  [[ "$probe_modified" =~ ^[0-9]+$ ]] || probe_modified=0
  probe_now="$(date +%s)"
  if [ "$probe_modified" -gt "$probe_now" ] || [ "$((probe_now - probe_modified))" -ge 300 ]; then
    run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
      /usr/bin/timeout --signal=TERM --kill-after=5 45 /usr/bin/php "$announcement_worker" --health-check --record-health >&"$LOG_FD" 2>&1 \
      || log "Announcement worker health probe failed; inspect the sanitized worker status."
  fi
fi


# Generated speech and composite audio are short-lived delivery artifacts.
/usr/bin/timeout --signal=TERM --kill-after=5 15 /usr/bin/python3 -I \
  "$RUNTIME_DIR/sls_mail_evidence.py" >&"$LOG_FD" 2>&1 \
  || log "Read-only Postfix evidence was not refreshed; existing mail evidence may be stale."
/usr/bin/timeout --signal=TERM --kill-after=5 15 /usr/bin/python3 -I \
  "$RUNTIME_DIR/sls_support_snapshot.py" >&"$LOG_FD" 2>&1 \
  || log "The redacted support snapshot was not refreshed; existing monitoring data may be stale."
/usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
  /usr/bin/timeout --signal=TERM --kill-after=5 300 /usr/bin/python3 -I \
  "$RUNTIME_DIR/sls_storage_maintenance.py" >&"$LOG_FD" 2>&1 \
  || log "Some storage retention stages were deferred; inspect the preceding retention detail."

# Remove expired PKCE transactions, one-time proofs, test messages and claims
# while retaining active replay tombstones for their complete security window.
if [ -r "$MODULE_DIR/EnterpriseIdentityStore.php" ]; then
  run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=5 30 /usr/bin/php -r '
    require $argv[1];
    foreach (["enterprise-state", "control-idempotency"] as $name) {
      $path = $argv[2]."/".$name;
      if (is_dir($path) && !is_link($path)) { (new \SLS\MassNotify\EnterpriseIdentityStore($path))->compact(time()); }
    }
  ' "$MODULE_DIR/EnterpriseIdentityStore.php" "${CONFIG_FILE%/*}" >&"$LOG_FD" 2>&1 \
    || log "Expired Enterprise Labs state could not be compacted; it will retry during the next maintenance cycle."
fi

# Dashboard and Framework upgrades can replace the two managed widget files or
# the menu ordering hook. Detect that drift and restore only those integration
# points from the installed module, then refresh their trusted local signatures.
integration_drift=0
if safe_single_regular_file "$INTEGRATION_REPAIR_FILE"; then
  integration_drift=1
elif [ -e "$INTEGRATION_REPAIR_FILE" ] || [ -L "$INTEGRATION_REPAIR_FILE" ]; then
  log "Ignored an unsafe dashboard/menu integration retry marker; preserve it for review."
fi
for relative_path in sections/SlsMassNotifyAnnouncement.class.php views/sections/sls-mass-notify-announcement.php; do
  source_path="$MODULE_DIR/dashboard/$relative_path"
  target_path="$DASHBOARD_DIR/$relative_path"
  if safe_single_regular_file "$source_path" \
    && { ! safe_single_regular_file "$target_path" \
      || ! /usr/bin/timeout --signal=KILL 5 /usr/bin/cmp -s -- "$source_path" "$target_path"; }; then
    integration_drift=1
  fi
done
if ! safe_single_regular_file "$MENU_FILE" \
  || ! /usr/bin/timeout --signal=KILL 5 /usr/bin/grep -Fq -- \
    'SLS Mass Notifications menu placement:' "$MENU_FILE"; then
  integration_drift=1
fi
if [ "$integration_drift" -eq 1 ] && ! acquire_mutation_coordination >&"$LOG_FD" 2>&1; then
  integration_drift=0
  log "Dashboard/menu repair deferred: active delivery, paging, or configuration editing did not become safely idle."
fi
if [ "$integration_drift" -eq 1 ] && ! verify_installed_sls_module >&"$LOG_FD" 2>&1; then
  integration_drift=0
  log "Dashboard/menu repair deferred: the installed SLS module differs from its authenticated publisher inventory. Run Repair Installation from a verified release."
  release_mutation_coordination
fi
if [ "$integration_drift" -eq 1 ]; then
  log "FreePBX update drift detected; restoring Mass Notify dashboard/menu integration"
  integration_ok=1
  if run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=10 300 /usr/bin/env SLS_MASS_NOTIFY_DEFER_SIGNING=1 \
    /usr/bin/php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->repairUpdateSensitiveIntegration(); exit(0);' >&"$LOG_FD" 2>&1; then
    # Widget/menu files are web-account data. Do not invoke root FreePBX
    # bootstrap or a global ownership sweep to repair these two UI hooks.
    for module in dashboard framework; do
      [ -d "/var/www/html/admin/modules/$module" ] || continue
      run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=10 360 \
        "$SIGNER" "$module" >&"$LOG_FD" 2>&1 \
        || { integration_ok=0; log "Unable to refresh local signature for $module"; }
    done
  else
    integration_ok=0
    log "Automatic dashboard/menu integration repair failed; it will retry on the next maintenance run"
  fi
  if ! verify_mutation_idle >&"$LOG_FD" 2>&1; then
    mark_integration_retry
    log "Dashboard/menu repair idle verification failed; preserve delivery history and review the maintenance log."
    release_mutation_coordination
    exit 1
  fi
  release_mutation_coordination
  if [ "$integration_ok" -eq 1 ] && clear_integration_retry; then
    log "Mass Notify dashboard/menu integration restored after FreePBX update drift"
  else
    mark_integration_retry
    log "Automatic dashboard/menu integration repair failed; runtime permissions or signatures require attention and will be retried."
  fi
fi

claim_request() {
  local path="$1" label="$2" kind="$3" claimed status
  if claimed="$(/usr/bin/python3 -I - "$path" "$kind" 2>&"$LOG_FD" <<'PYREQUEST'
import fcntl
import os
import pwd
import re
import stat
import sys

path, kind = sys.argv[1:]
parts = path.split('/')[1:]
if not path.startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
    raise SystemExit(2)
account = pwd.getpwnam('asterisk')
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', directory_flags)
fd = -1
claim_name = ''
claim_matches_fd = False
def restore_failed_claim():
    if not claim_name or not claim_matches_fd:
        return
    try:
        held = os.fstat(fd)
        named = os.stat(claim_name, dir_fd=parent, follow_symlinks=False)
        if (not stat.S_ISREG(held.st_mode) or held.st_nlink != 1
                or (held.st_dev, held.st_ino) != (named.st_dev, named.st_ino)):
            raise RuntimeError('claim identity changed')
        try:
            os.link(claim_name, parts[-1], src_dir_fd=parent,
                    dst_dir_fd=parent, follow_symlinks=False)
        except FileExistsError:
            print('SLS maintenance preserved the failed private claim because a newer request exists: '
                  + os.path.join(os.path.dirname(path), claim_name), file=sys.stderr)
            return
        restored = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
        if ((restored.st_dev, restored.st_ino) != (held.st_dev, held.st_ino)
                or restored.st_nlink != 2):
            raise RuntimeError('restored claim identity changed')
        os.unlink(claim_name, dir_fd=parent)
        os.fsync(parent)
    except (OSError, RuntimeError) as error:
        print('SLS maintenance preserved a failed private claim for review ('
              + str(error) + '): ' + os.path.join(os.path.dirname(path), claim_name),
              file=sys.stderr)
try:
    for component in parts[:-1]:
        child = os.open(component, directory_flags, dir_fd=parent)
        metadata = os.fstat(child)
        sticky_root = metadata.st_uid == 0 and bool(metadata.st_mode & stat.S_ISVTX)
        if (not stat.S_ISDIR(metadata.st_mode)
                or metadata.st_uid not in (0, account.pw_uid)
                or metadata.st_gid not in (0, account.pw_gid)
                or (metadata.st_mode & 0o022 and not sticky_root)):
            os.close(child)
            raise RuntimeError('unsafe request parent')
        os.close(parent)
        parent = child
    try:
        fd = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC,
                     dir_fd=parent)
    except FileNotFoundError:
        raise SystemExit(3)
    before = os.fstat(fd)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_uid not in (0, account.pw_uid)
            or before.st_gid not in (0, account.pw_gid)
            or before.st_mode & 0o022 or before.st_size > 1024):
        raise RuntimeError('unsafe request marker')
    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    body = bytearray()
    while len(body) <= 1024:
        chunk = os.read(fd, min(1025 - len(body), 4096))
        if not chunk:
            break
        body.extend(chunk)
    if len(body) > 1024:
        raise RuntimeError('oversized request marker')
    timestamp = rb'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\n'
    mode = 'run'
    if kind == 'timezone':
        valid = body == b'1\n'
    elif kind == 'update':
        if re.fullmatch(timestamp + rb'check-only\n', body):
            valid = True
            mode = 'check-only'
        else:
            # Empty markers were created by releases before request metadata was
            # introduced. File identity and private ownership remain mandatory.
            valid = body == b'' or bool(re.fullmatch(timestamp, body))
    elif kind in ('repair', 'uninstall'):
        valid = body == b'' or bool(re.fullmatch(timestamp, body))
    else:
        raise RuntimeError('unknown request kind')
    if not valid:
        raise RuntimeError('invalid request marker contents')
    after = os.fstat(fd)
    current = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    identity = lambda item: (item.st_dev, item.st_ino, item.st_uid, item.st_gid,
                             item.st_mode, item.st_size, item.st_mtime_ns,
                             item.st_ctime_ns, item.st_nlink)
    if identity(before) != identity(after) or identity(after) != identity(current):
        raise RuntimeError('request marker changed during inspection')
    claim_name = '.sls-maintenance-claim-' + parts[-1] + '-' + os.urandom(12).hex()
    os.rename(parts[-1], claim_name, src_dir_fd=parent, dst_dir_fd=parent)
    claimed = os.stat(claim_name, dir_fd=parent, follow_symlinks=False)
    claimed_identity = lambda item: (item.st_dev, item.st_ino, item.st_uid, item.st_gid,
                                     item.st_mode, item.st_size, item.st_mtime_ns,
                                     item.st_nlink)
    # A successful rename can update ctime. The inode, content metadata and
    # link count must otherwise remain the exact object inspected above.
    if claimed_identity(claimed) != claimed_identity(after):
        raise RuntimeError('request marker changed while being claimed')
    claim_matches_fd = True
    os.fchown(fd, 0, 0)
    os.fchmod(fd, 0o600)
    os.fsync(fd)
    secured = os.fstat(fd)
    named = os.stat(claim_name, dir_fd=parent, follow_symlinks=False)
    if (secured.st_uid != 0 or secured.st_gid != 0 or stat.S_IMODE(secured.st_mode) != 0o600
            or secured.st_nlink != 1
            or (secured.st_dev, secured.st_ino) != (named.st_dev, named.st_ino)):
        raise RuntimeError('claimed request could not be secured')
    os.fsync(parent)
    print(os.path.join(os.path.dirname(path), claim_name) + '\t' + mode)
except RuntimeError as error:
    restore_failed_claim()
    print('SLS maintenance request rejected: ' + str(error), file=sys.stderr)
    raise SystemExit(2)
except OSError:
    restore_failed_claim()
    print('SLS maintenance request rejected: unsafe request storage', file=sys.stderr)
    raise SystemExit(2)
finally:
    if fd >= 0:
        os.close(fd)
    os.close(parent)
PYREQUEST
)"; then
    printf '%s\n' "$claimed"
    return 0
  else
    status=$?
    [ "$status" -eq 3 ] || log "Rejected unsafe or invalid $label request marker; it was preserved for review."
    return "$status"
  fi
}

finish_request_claim() {
  local operation="$1" claim="$2" original="${3:-}"
  /usr/bin/python3 -I - "$operation" "$claim" "$original" <<'PYREQUESTFINISH'
import os
import pwd
import secrets
import stat
import sys

operation, claim, original = sys.argv[1:]
parts = claim.split('/')[1:]
if (operation not in ('consume', 'restore') or not claim.startswith('/') or not parts
        or any(part in ('', '.', '..') for part in parts)
        or not parts[-1].startswith('.sls-maintenance-claim-')):
    raise SystemExit(2)
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
parent = os.open('/', flags)
fd = -1
try:
    for component in parts[:-1]:
        child = os.open(component, flags, dir_fd=parent)
        os.close(parent)
        parent = child
    try:
        fd = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW | os.O_CLOEXEC,
                     dir_fd=parent)
    except FileNotFoundError:
        raise SystemExit(0)
    metadata = os.fstat(fd)
    named = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
            or metadata.st_uid != 0 or metadata.st_gid != 0
            or stat.S_IMODE(metadata.st_mode) != 0o600
            or (metadata.st_dev, metadata.st_ino) != (named.st_dev, named.st_ino)):
        raise RuntimeError('unsafe claimed request')
    if operation == 'consume':
        os.unlink(parts[-1], dir_fd=parent)
    else:
        original_parts = original.split('/')[1:]
        if (not original.startswith('/') or original_parts[:-1] != parts[:-1]
                or any(part in ('', '.', '..') for part in original_parts)):
            raise RuntimeError('unsafe request restore destination')
        account = pwd.getpwnam('asterisk')
        request = os.pread(fd, 1025, 0)
        stable = os.fstat(fd)
        if (len(request) != metadata.st_size or metadata.st_size > 1024
                or (stable.st_dev, stable.st_ino, stable.st_size, stable.st_mtime_ns,
                    stable.st_ctime_ns, stable.st_uid, stable.st_gid,
                    stat.S_IMODE(stable.st_mode), stable.st_nlink) != (
                    metadata.st_dev, metadata.st_ino, metadata.st_size,
                    metadata.st_mtime_ns, metadata.st_ctime_ns, 0, 0, 0o600, 1)):
            raise RuntimeError('claimed request changed while it was being restored')
        restore_name = '.sls-maintenance-restore-' + secrets.token_hex(16)
        restore_fd = -1
        restore_identity = None
        try:
            restore_fd = os.open(
                restore_name,
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                0o600,
                dir_fd=parent,
            )
            restore_identity = os.fstat(restore_fd)
            offset = 0
            while offset < len(request):
                written = os.write(restore_fd, request[offset:])
                if written <= 0:
                    raise RuntimeError('restored request write made no progress')
                offset += written
            os.fchown(restore_fd, account.pw_uid, account.pw_gid)
            os.fchmod(restore_fd, 0o640)
            os.fsync(restore_fd)
            prepared = os.fstat(restore_fd)
            if (not stat.S_ISREG(prepared.st_mode) or prepared.st_nlink != 1
                    or prepared.st_uid != account.pw_uid or prepared.st_gid != account.pw_gid
                    or stat.S_IMODE(prepared.st_mode) != 0o640
                    or prepared.st_size != len(request)):
                raise RuntimeError('prepared request restore could not be verified')
            try:
                # Linking a fully prepared private file is an atomic
                # no-overwrite commit. A request queued meanwhile wins.
                os.link(restore_name, original_parts[-1], src_dir_fd=parent,
                        dst_dir_fd=parent, follow_symlinks=False)
            except FileExistsError:
                raise RuntimeError('a newer request already occupies the restore destination')
            restored = os.stat(original_parts[-1], dir_fd=parent, follow_symlinks=False)
            if (restored.st_dev, restored.st_ino, restored.st_uid, restored.st_gid,
                    stat.S_IMODE(restored.st_mode), restored.st_nlink,
                    restored.st_size) != (
                    prepared.st_dev, prepared.st_ino, account.pw_uid,
                    account.pw_gid, 0o640, 2, len(request)):
                raise RuntimeError('committed request restore could not be verified')
            os.unlink(restore_name, dir_fd=parent)
            restore_name = ''
            restored = os.stat(original_parts[-1], dir_fd=parent, follow_symlinks=False)
            if (restored.st_dev, restored.st_ino, restored.st_uid, restored.st_gid,
                    stat.S_IMODE(restored.st_mode), restored.st_nlink,
                    restored.st_size) != (
                    prepared.st_dev, prepared.st_ino, account.pw_uid,
                    account.pw_gid, 0o640, 1, len(request)):
                raise RuntimeError('restored request final state could not be verified')
            os.unlink(parts[-1], dir_fd=parent)
        finally:
            if restore_fd >= 0:
                os.close(restore_fd)
            if restore_name:
                try:
                    candidate = os.stat(restore_name, dir_fd=parent, follow_symlinks=False)
                    if restore_identity is not None and (
                            candidate.st_dev, candidate.st_ino) == (
                            restore_identity.st_dev, restore_identity.st_ino):
                        os.unlink(restore_name, dir_fd=parent)
                        os.fsync(parent)
                except FileNotFoundError:
                    pass
    os.fsync(parent)
finally:
    if fd >= 0:
        os.close(fd)
    os.close(parent)
PYREQUESTFINISH
}

# Send each currently active operational fault once. This runs inside the same
# root maintenance lock as status-producing repair/update work, and failure to
# submit a notice is logged for a bounded retry without blocking maintenance.
if timezone_claim_result="$(claim_request "$TIMEZONE_REQUEST_FILE" "timezone" "timezone")"; then
  timezone_claim="${timezone_claim_result%%$'\t'*}"
  ACTIVE_ACTION="timezone"
  if ! admit_root_runtime >&"$LOG_FD" 2>&1 || ! acquire_mutation_coordination >&"$LOG_FD" 2>&1; then
    finish_request_claim restore "$timezone_claim" "$TIMEZONE_REQUEST_FILE" >&"$LOG_FD" 2>&1 || log "The claimed timezone request could not be restored; preserve it for review: $timezone_claim"
    fail_maintenance "timezone_apply_deferred" "The saved timezone could not be applied while delivery or configuration activity was present. Review the setup wizard and retry once activity finishes." 2
  fi
  if run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=5 120 \
    /usr/bin/python3 -I "$PRIVILEGED_HELPER" timezone --apply >&"$LOG_FD" 2>&1; then
    finish_request_claim consume "$timezone_claim" >&"$LOG_FD" 2>&1 || fail_maintenance "timezone_request_cleanup_failed" "The timezone was applied, but its protected request claim could not be removed. Preserve the maintenance log before retrying." 2
    verify_mutation_idle >&"$LOG_FD" 2>&1 || fail_maintenance "timezone_state_changed" "Timezone apply could not verify preserved configuration and delivery history. Preserve the maintenance log." 2
    release_mutation_coordination
    log "Applied the explicitly saved setup timezone"
    ACTIVE_ACTION=""
  else
    finish_request_claim restore "$timezone_claim" "$TIMEZONE_REQUEST_FILE" >&"$LOG_FD" 2>&1 || log "The failed timezone request could not be restored; preserve it for review: $timezone_claim"
    fail_maintenance "timezone_apply_failed" "The PBX rejected the saved timezone. Review the maintenance log and select a valid IANA timezone in the setup wizard." 2
  fi
fi
if [ -x "$RUNTIME_DIR/sls_system_notifications.py" ]; then
  if ! /usr/bin/timeout --signal=TERM --kill-after=5 20 \
    /usr/bin/python3 -I "$RUNTIME_DIR/sls_system_notifications.py" >&"$LOG_FD" 2>&1; then
    log "System/error email notification check failed; it will retry later"
  fi
fi

if uninstall_claim_result="$(claim_request "$UNINSTALL_REQUEST_FILE" "uninstall" "uninstall")"; then
  uninstall_claim="${uninstall_claim_result%%$'\t'*}"
  ACTIVE_ACTION="uninstall"
  finish_request_claim consume "$uninstall_claim" >&"$LOG_FD" 2>&1 || fail_maintenance "uninstall_request_cleanup_failed" "The complete-uninstall request could not be consumed safely. No uninstall was started." 2
  log "Starting queued complete uninstall"
  write_maintenance_progress "uninstall" "running" "Removing Mass Notify runtime, FreePBX integration, APIs, logs, and protected configuration."
  if SLS_MASS_NOTIFY_PURGE_CONFIG=1 /usr/bin/timeout --signal=TERM --kill-after=30 3600 \
    "$RUNTIME_DIR/sls_mass_notify_uninstall.sh" >&"$LOG_FD" 2>&1; then
    rm -f "$MAINTENANCE_PROGRESS_FILE"
  else
    uninstall_status=$?
    log "Queued complete uninstall reported an error"
    fail_maintenance "process_failed" "Complete uninstall reported an error. Review the PBX console and uninstall log before retrying." "$uninstall_status"
  fi
  exit 0
fi

update_claim_status=0
update_claim_result="$(claim_request "$UPDATE_REQUEST_FILE" "manual update" "update")" || update_claim_status=$?
if [ "$update_claim_status" -eq 0 ]; then
  update_claim="${update_claim_result%%$'\t'*}"
  update_claim_mode="${update_claim_result#*$'\t'}"
  ACTIVE_ACTION="update"
  [ "$update_claim_mode" = "check-only" ] && update_check_only=1 || update_check_only=0
  finish_request_claim consume "$update_claim" >&"$LOG_FD" 2>&1 || fail_maintenance "update_request_cleanup_failed" "The update request could not be consumed safely. No updater was executed." 2
  log "Starting queued manual update"
  write_update_progress "checking" "Checking the verified release feed."
  if ! /bin/bash -n "$RUNTIME_DIR/sls_mass_notify_update.sh" >&"$LOG_FD" 2>&1; then
    fail_maintenance "update_script_syntax_error" "The installed updater contains a shell syntax error. No updater was executed." 2
  fi
  export SLS_MASS_NOTIFY_CHECK_ONLY="$update_check_only"
  if SLS_MASS_NOTIFY_MANUAL_UPDATE=1 /usr/bin/timeout --signal=TERM --kill-after=30 1800 \
    "$RUNTIME_DIR/sls_mass_notify_update.sh" >&"$LOG_FD" 2>&1; then
    if ! update_progress_is complete; then
      fail_maintenance "process_failed" "The update process exited without confirming completion. Review Notification Logs for details."
    fi
  else
    update_status=$?
    update_category="process_failed"
    case "$update_status" in
      124|137) update_category="timeout" ;;
    esac
    fail_maintenance "$update_category" "The update process failed. Review Notification Logs for details." "$update_status"
  fi
  log "Queued manual update completed successfully"
  exit 0
elif [ "$update_claim_status" -ne 3 ]; then
  ACTIVE_ACTION="update"
  fail_maintenance "update_request_invalid" "The update request is invalid. Reload General Settings and request a new check; no updater was executed." 2
fi

repair_claim_status=0
repair_claim_result="$(claim_request "$REQUEST_FILE" "installation repair" "repair")" || repair_claim_status=$?
[ "$repair_claim_status" -eq 0 ] || {
  [ "$repair_claim_status" -eq 3 ] && exit 0
  exit 1
}
repair_claim="${repair_claim_result%%$'\t'*}"
finish_request_claim consume "$repair_claim" >&"$LOG_FD" 2>&1 || {
  log "The installation repair request could not be consumed safely."
  exit 1
}

log "Starting queued installation repair"
ACTIVE_ACTION="repair"
write_maintenance_progress "repair" "running" "Refreshing runtime files, permissions, dialplan, dashboard integration, and local signatures."
if ! /usr/bin/timeout --signal=TERM --kill-after=5 60 \
  /usr/bin/python3 -I "$RUNTIME_DIR/sls_config.py" "$CONFIG_FILE" >/dev/null 2>&"$LOG_FD"; then
  fail_maintenance "protected_config_validation_failed" "The protected central configuration is invalid or unavailable. Repair was not started." 2
fi
if ! acquire_mutation_coordination >&"$LOG_FD" 2>&1; then
  fail_maintenance "active_delivery_or_configuration" "Repair did not start because notifications, paging, configuration editing, or collector health could not be confirmed idle. Allow current activity to finish and retry; no delivery was interrupted." 2
fi
if ! verify_installed_sls_module >&"$LOG_FD" 2>&1; then
  fail_maintenance "publisher_module_verification_failed" "Repair stopped because the installed SLS module differs from its authenticated publisher inventory. Restore the same verified release before retrying." 2
fi
# Root operations use only the immutable publisher generation. All FreePBX
# bootstrap, module hooks, DB access and reload hooks run as asterisk.
repair_ok=1
repair_status=0
for phase in prepare dependencies; do
  if [ "$repair_ok" -eq 1 ]; then
    [ "$phase" = dependencies ] && phase_timeout=2940 || phase_timeout=600
    run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=10 "$phase_timeout" \
      /usr/bin/python3 -I "$PRIVILEGED_HELPER" "$phase" --apply >&"$LOG_FD" 2>&1 \
      || { repair_status=$?; repair_ok=0; }
  fi
done
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=10 600 /usr/bin/env SLS_MASS_NOTIFY_DEFER_SIGNING=1 \
    /usr/bin/php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->installUnprivilegedPhase();' >&"$LOG_FD" 2>&1 \
    || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=10 300 \
    /usr/bin/python3 -I "$PRIVILEGED_HELPER" activate --apply >&"$LOG_FD" 2>&1 \
    || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=10 600 /usr/bin/env SLS_MASS_NOTIFY_PRESERVE_PENDING=1 \
    /usr/sbin/fwconsole reload >&"$LOG_FD" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  for module in slsmassnotifyserver dashboard framework; do
    [ -d "/var/www/html/admin/modules/$module" ] || continue
    run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=10 360 \
      "$SIGNER" "$module" >&"$LOG_FD" 2>&1 || { repair_status=$?; repair_ok=0; }
  done
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/timeout --signal=TERM --kill-after=10 300 \
    /usr/bin/python3 -I "$PRIVILEGED_HELPER" verify >&"$LOG_FD" 2>&1 \
    || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=10 300 /usr/bin/php -r '
require "/etc/freepbx.conf";
$obj = \FreePBX::Create()->Slsmassnotifyserver;
$obj->verifyUnprivilegedIntegration();
' >&"$LOG_FD" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  verify_mutation_idle >&"$LOG_FD" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/env -u GITHUB_TOKEN -u GH_TOKEN -u SLS_MASS_NOTIFY_GITHUB_TOKEN /usr/sbin/runuser -u asterisk -- \
    /usr/bin/timeout --signal=TERM --kill-after=10 300 /usr/bin/php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->finalizeVerifiedUnprivilegedRestore();' >&"$LOG_FD" 2>&1 \
    || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  log "Queued installation repair completed"
  clear_install_failure
  write_maintenance_progress "repair" "complete" "Installation repair completed successfully."
else
  log "Queued installation repair failed"
  write_install_failure "protected repair" "Check the failed dependency, Asterisk capability, local signer, or FreePBX reload step in the maintenance log, correct it, and run Repair Installation again."
  fail_maintenance "repair_failed" "Installation repair failed. Review Notification Logs before retrying." "$repair_status"
fi
