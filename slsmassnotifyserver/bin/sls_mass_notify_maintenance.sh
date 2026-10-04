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

# Open only a root-owned regular file through trusted directories. Creation is
# exclusive; an existing file is never truncated or written before fd identity
# verification. Sticky root-owned /tmp and /run/lock are permitted.
open_root_owned_file() {
  local output_variable="$1" protected_path="$2"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 - "$protected_path" <<'PY'
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

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
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
  run_without_maintenance_lock /usr/bin/python3 -I "$PRIVILEGED_HELPER" admit
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
  exec {MUTATION_KEEPALIVE_FD}>&"${SLS_MAINTENANCE_GUARD[1]}"
  local status
  if ! IFS= read -r status <&"${SLS_MAINTENANCE_GUARD[0]}" || [ "$status" != ready ]; then
    release_mutation_coordination
    return 1
  fi
  if ! run_without_maintenance_lock /usr/bin/python3 -I "$RUNTIME_DIR/sls_install_idle.py" --data "${CONFIG_FILE%/*}" inspect >"$MUTATION_BASELINE_DIR/before.json"; then
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
        if info.st_uid not in (0, account.pw_uid) or (info.st_mode & 0o002 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX)):
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
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in (0, account.pw_uid) or before.st_size > 16777216:
                raise RuntimeError('Unsafe or oversized settings file')
            digest = hashlib.sha256(); size = 0
            while True:
                chunk = os.read(fd, 65536)
                if not chunk: break
                size += len(chunk)
                if size > 16777216: raise RuntimeError('Settings file exceeds size limit')
                digest.update(chunk)
            after = os.fstat(fd); current = os.stat(name, dir_fd=parent, follow_symlinks=False)
            identity = lambda item: (item.st_dev, item.st_ino, item.st_size, item.st_mtime_ns, item.st_ctime_ns, item.st_nlink)
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
  run_without_maintenance_lock /usr/bin/python3 -I "$RUNTIME_DIR/sls_install_idle.py" --data "${CONFIG_FILE%/*}" inspect --baseline "$MUTATION_BASELINE_DIR/before.json" || return 1
  MUTATION_VERIFIED=1
}

release_mutation_coordination() {
  if [ -n "${MUTATION_KEEPALIVE_FD:-}" ]; then
    printf 'release\n' >&"$MUTATION_KEEPALIVE_FD" 2>/dev/null || true
    exec {MUTATION_KEEPALIVE_FD}>&-
    MUTATION_KEEPALIVE_FD=""
  fi
  [ -z "${MUTATION_GUARD_PID:-}" ] || wait "$MUTATION_GUARD_PID" 2>/dev/null || true
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
        os.close(parent); parent = child
    try: existing = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError: existing = None
    if existing is not None and (not stat.S_ISREG(existing.st_mode) or existing.st_nlink != 1
                                 or existing.st_uid not in (0, account.pw_uid)):
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
        try: os.unlink(temporary, dir_fd=parent)
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
import stat
import sys
fd = -1
parent = -1
try:
    parts = sys.argv[1].split('/')[1:]
    if not sys.argv[1].startswith('/') or not parts or any(part in ('', '.', '..') for part in parts):
        raise ValueError('unsafe status path')
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    for part in parts[:-1]:
        child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
        os.close(parent); parent = child
    fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 65536:
        raise ValueError('unsafe status file')
    with os.fdopen(fd, 'rb') as handle:
        fd = -1
        body = handle.read(65537)
    if len(body) > 65536: raise ValueError('oversized status')
    progress = json.loads(body)
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
  /usr/sbin/runuser -u asterisk -- /usr/bin/php -r '
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
  /usr/sbin/runuser -u asterisk -- /usr/bin/php -r '
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
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", directory_flags)
config_fd = -1
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    account = pwd.getpwnam("asterisk")
    before = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in (0, account.pw_uid):
        raise SystemExit(3)
    config_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
    metadata = os.fstat(config_fd)
    current = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
            or metadata.st_uid not in (0, account.pw_uid)
            or (before.st_dev, before.st_ino, before.st_uid) != (metadata.st_dev, metadata.st_ino, metadata.st_uid)
            or (current.st_dev, current.st_ino, current.st_mode, current.st_nlink)
            != (metadata.st_dev, metadata.st_ino, metadata.st_mode, 1)):
        raise SystemExit(3)
    os.fchmod(config_fd, 0o640)
    os.fchown(config_fd, account.pw_uid, account.pw_gid)
    verified = os.fstat(config_fd)
    current = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
    if (stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_uid != account.pw_uid
            or verified.st_gid != account.pw_gid or verified.st_nlink != 1
            or (current.st_dev, current.st_ino) != (verified.st_dev, verified.st_ino)):
        raise SystemExit(4)
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

repair_runtime_permissions() {
  [ -e "$RUNTIME_DIR" ] || [ -L "$RUNTIME_DIR" ] || return 0
  RUNTIME_PERMISSION_ROOT="$RUNTIME_DIR" /usr/bin/python3 -I - <<'PY' || return 1
import os
import pwd
import stat
import re

root = os.environ["RUNTIME_PERMISSION_ROOT"]
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
account = pwd.getpwnam("asterisk")
parts = [part for part in root.split("/") if part]
if not root.startswith("/") or not parts or ".." in parts or "\x00" in root:
    raise RuntimeError("unsafe runtime root")
root_fd = os.open("/", flags)
try:
    for component in parts:
        next_fd = os.open(component, flags, dir_fd=root_fd)
        os.close(root_fd)
        root_fd = next_fd
except BaseException:
    os.close(root_fd)
    raise

def same_entry(before, opened, current, directory=False):
    expected_type = stat.S_ISDIR if directory else stat.S_ISREG
    return (expected_type(opened.st_mode) and opened.st_uid in (0, account.pw_uid)
            and (directory or opened.st_nlink == 1)
            and (before.st_dev, before.st_ino, before.st_uid) == (opened.st_dev, opened.st_ino, opened.st_uid)
            and (current.st_dev, current.st_ino, current.st_mode, current.st_nlink)
            == (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_nlink))

def secure_tree(directory_fd, relative=""):
    directory_metadata = os.fstat(directory_fd)
    if not stat.S_ISDIR(directory_metadata.st_mode) or directory_metadata.st_uid not in (0, account.pw_uid):
        raise RuntimeError("runtime directory has unexpected type or ownership")
    os.fchown(directory_fd, 0, 0)
    os.fchmod(directory_fd, 0o700 if re.fullmatch(r"piper/\.replacement-[a-f0-9]{24}", relative) else 0o755)
    for name in os.listdir(directory_fd):
        metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        child_relative = f"{relative}/{name}" if relative else name
        if stat.S_ISDIR(metadata.st_mode):
            child_fd = os.open(name, flags, dir_fd=directory_fd)
            try:
                current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                if not same_entry(metadata, os.fstat(child_fd), current, directory=True):
                    raise RuntimeError(f"runtime directory changed during repair: {child_relative}")
                secure_tree(child_fd, child_relative)
            finally:
                os.close(child_fd)
        elif stat.S_ISREG(metadata.st_mode):
            if metadata.st_nlink != 1 or metadata.st_uid not in (0, account.pw_uid):
                raise RuntimeError(f"unsafe runtime file: {child_relative}")
            file_fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0), dir_fd=directory_fd)
            try:
                current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                if not same_entry(metadata, os.fstat(file_fd), current):
                    raise RuntimeError(f"runtime entry changed during repair: {child_relative}")
                os.fchown(file_fd, 0, 0)
                executable = (
                    "/" not in child_relative
                    and (
                        child_relative.endswith((".sh", ".py"))
                        or child_relative == "sls_mass_notify_schedule_worker.php"
                        or child_relative == "sls_mass_notify_announcement_worker.php"
                        or child_relative == "sls_mass_notify_automation_worker.php"
                        or child_relative == "sls_mass_notify_delivery_authorization.php"
                        or child_relative == "sls_mass_notify_panic.php"
                        or child_relative == "sls_mass_notify_live_paging.php"
                    )
                ) or re.sub(r"^piper/\.replacement-[a-f0-9]{24}/", "piper/", child_relative).startswith("piper/venv/bin/")
                os.fchmod(file_fd, 0o600 if child_relative == "piper/.replacement.lock" else (0o755 if executable else 0o644))
            finally:
                os.close(file_fd)
        elif stat.S_ISLNK(metadata.st_mode):
            current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
            if (metadata.st_nlink != 1 or metadata.st_uid not in (0, account.pw_uid)
                    or (current.st_dev, current.st_ino, current.st_mode, current.st_nlink)
                    != (metadata.st_dev, metadata.st_ino, metadata.st_mode, 1)):
                raise RuntimeError(f"unsafe runtime symbolic link: {child_relative}")
            # Its parent has already been secured root:root 0755. Never follow
            # interpreter/lib64 links when repairing their own ownership.
            os.chown(name, 0, 0, dir_fd=directory_fd, follow_symlinks=False)
        else:
            raise RuntimeError(f"unsupported runtime entry: {child_relative}")

try:
    secure_tree(root_fd)
finally:
    os.close(root_fd)
PY

  if [ -x "$RUNTIME_DIR/piper/venv/bin/piper" ]; then
    [ -f "$RUNTIME_DIR/sls_mass_notify_install_piper_voices.sh" ] || return 1
    /bin/bash "$RUNTIME_DIR/sls_mass_notify_install_piper_voices.sh" --repair-permissions-only || return 1
  fi
  return 0
}

[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1
trap finish_maintenance EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
MAINTENANCE_LOCK_FD=""
open_root_owned_file MAINTENANCE_LOCK_FD "$LOCK_FILE" || {
  log "The protected maintenance lock could not be opened safely."
  exit 1
}
flock -n "$MAINTENANCE_LOCK_FD" || exit 0
if ! admit_root_runtime >> "$LOG_FILE" 2>&1; then
  ACTIVE_ACTION="trust"
  fail_maintenance "publisher_runtime_verification_failed" "The protected SLS runtime does not match an enrolled publisher release. Use the verified release installer to restore it; no automatic baseline was enrolled." 2
fi
if ! secure_central_config; then
  ACTIVE_ACTION="config"
  fail_maintenance "protected_config_validation_failed" "The protected central configuration could not be secured. Maintenance was stopped." 2
fi

# The protected daily journal avoids scanning configuration backups each minute.
if ! encryption_result="$(/usr/bin/timeout 120 /usr/bin/python3 -I "$RUNTIME_DIR/sls_config_crypto.py" daily 2>> "$LOG_FILE")"; then
  log "$encryption_result"
  ACTIVE_ACTION="config"
  fail_maintenance "configuration_encryption_failed" "SLS configuration encryption or annual key rotation could not be verified. Preserve the protected keyring and review the maintenance log before restoring settings." 2
fi
case "$encryption_result" in
  *'"rotated":true'*|*'"encrypted_files":'[1-9]*) log "$encryption_result" ;;
esac

# Generated speech and composite audio are short-lived delivery artifacts.
/usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I "$RUNTIME_DIR/sls_storage_maintenance.py" >> "$LOG_FILE" 2>&1 || log "Some storage retention stages were deferred; inspect the preceding retention detail."

# Reconciliation never sends an announcement. Probe worker bootstrap/storage as
# its service account, so a root-only success cannot mask delivery failures.
announcement_worker="$RUNTIME_DIR/sls_mass_notify_announcement_worker.php"
if [ -r "$announcement_worker" ]; then
  run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/timeout 30 /usr/bin/php "$announcement_worker" --reconcile >> "$LOG_FILE" 2>&1 \
    || log "Announcement job reconciliation failed; it will retry later."
  worker_probe="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/announcement-jobs/worker-probe.json"
  probe_modified="$(stat -c '%Y' "$worker_probe" 2>/dev/null || printf '0')"
  [[ "$probe_modified" =~ ^[0-9]+$ ]] || probe_modified=0
  probe_now="$(date +%s)"
  if [ "$probe_modified" -gt "$probe_now" ] || [ "$((probe_now - probe_modified))" -ge 300 ]; then
    run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/timeout 45 /usr/bin/php "$announcement_worker" --health-check --record-health >> "$LOG_FILE" 2>&1 \
      || log "Announcement worker health probe failed; inspect the sanitized worker status."
  fi
fi

# Dashboard and Framework upgrades can replace the two managed widget files or
# the menu ordering hook. Detect that drift and restore only those integration
# points from the installed module, then refresh their trusted local signatures.
integration_drift=0
for relative_path in sections/SlsMassNotifyAnnouncement.class.php views/sections/sls-mass-notify-announcement.php; do
  source_path="$MODULE_DIR/dashboard/$relative_path"
  target_path="$DASHBOARD_DIR/$relative_path"
  if [ -f "$source_path" ] && ! cmp -s "$source_path" "$target_path"; then
    integration_drift=1
  fi
done
if [ -r "$MENU_FILE" ] && ! grep -Fq 'SLS Mass Notifications menu placement:' "$MENU_FILE"; then
  integration_drift=1
fi
if [ "$integration_drift" -eq 1 ] && ! acquire_mutation_coordination >> "$LOG_FILE" 2>&1; then
  integration_drift=0
  log "Dashboard/menu repair deferred: active delivery, paging, or configuration editing did not become safely idle."
fi
if [ "$integration_drift" -eq 1 ]; then
  log "FreePBX update drift detected; restoring Mass Notify dashboard/menu integration"
  integration_ok=1
  if run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/env SLS_MASS_NOTIFY_DEFER_SIGNING=1 /usr/bin/php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->repairUpdateSensitiveIntegration(); exit(0);' >> "$LOG_FILE" 2>&1; then
    # Widget/menu files are web-account data. Do not invoke root FreePBX
    # bootstrap or a global ownership sweep to repair these two UI hooks.
    for module in dashboard framework; do
      [ -d "/var/www/html/admin/modules/$module" ] || continue
      run_without_maintenance_lock "$SIGNER" "$module" >> "$LOG_FILE" 2>&1 || { integration_ok=0; log "Unable to refresh local signature for $module"; }
    done
    if [ "$integration_ok" -eq 1 ]; then
      log "Mass Notify dashboard/menu integration restored after FreePBX update drift"
    else
      log "Automatic dashboard/menu integration repair failed; runtime permissions or signatures require attention"
    fi
  else
    log "Automatic dashboard/menu integration repair failed; it will retry on the next maintenance run"
  fi
  if ! verify_mutation_idle >> "$LOG_FILE" 2>&1; then
    log "Dashboard/menu repair idle verification failed; preserve delivery history and review the maintenance log."
    release_mutation_coordination
    exit 1
  fi
  release_mutation_coordination
fi

safe_request() {
  local path="$1"
  local label="$2"
  [ -e "$path" ] || return 1
  if [ -L "$path" ] || [ ! -f "$path" ]; then
    log "Rejected unsafe $label request marker"
    rm -f "$path"
    return 1
  fi
  local owner
  owner="$(stat -c '%U' "$path" 2>/dev/null || true)"
  if [ "$owner" != "asterisk" ] && [ "$owner" != "root" ]; then
    log "Rejected $label request owned by $owner"
    rm -f "$path"
    return 1
  fi
  return 0
}

# Send each currently active operational fault once. This runs inside the same
# root maintenance lock as status-producing repair/update work, and failure to
# submit a notice is logged for a bounded retry without blocking maintenance.
if safe_request "$TIMEZONE_REQUEST_FILE" "timezone"; then
  ACTIVE_ACTION="timezone"
  if ! admit_root_runtime >> "$LOG_FILE" 2>&1 || ! acquire_mutation_coordination >> "$LOG_FILE" 2>&1; then
    fail_maintenance "timezone_apply_deferred" "The saved timezone could not be applied while delivery or configuration activity was present. Review the setup wizard and retry once activity finishes." 2
  fi
  if run_without_maintenance_lock /usr/bin/python3 -I "$PRIVILEGED_HELPER" timezone --apply >> "$LOG_FILE" 2>&1; then
    rm -f "$TIMEZONE_REQUEST_FILE"
    verify_mutation_idle >> "$LOG_FILE" 2>&1 || fail_maintenance "timezone_state_changed" "Timezone apply could not verify preserved configuration and delivery history. Preserve the maintenance log." 2
    release_mutation_coordination
    log "Applied the explicitly saved setup timezone"
    ACTIVE_ACTION=""
  else
    fail_maintenance "timezone_apply_failed" "The PBX rejected the saved timezone. Review the maintenance log and select a valid IANA timezone in the setup wizard." 2
  fi
fi
if [ -x "$RUNTIME_DIR/sls_system_notifications.py" ]; then
  if ! /usr/bin/timeout 20 /usr/bin/python3 -I "$RUNTIME_DIR/sls_system_notifications.py" >> "$LOG_FILE" 2>&1; then
    log "System/error email notification check failed; it will retry later"
  fi
fi

if safe_request "$UNINSTALL_REQUEST_FILE" "uninstall"; then
  ACTIVE_ACTION="uninstall"
  rm -f "$UNINSTALL_REQUEST_FILE"
  log "Starting queued complete uninstall"
  write_maintenance_progress "uninstall" "running" "Removing Mass Notify runtime, FreePBX integration, APIs, logs, and protected configuration."
  if SLS_MASS_NOTIFY_PURGE_CONFIG=1 "$RUNTIME_DIR/sls_mass_notify_uninstall.sh" >> "$LOG_FILE" 2>&1; then
    rm -f "$MAINTENANCE_PROGRESS_FILE"
  else
    uninstall_status=$?
    log "Queued complete uninstall reported an error"
    fail_maintenance "process_failed" "Complete uninstall reported an error. Review the PBX console and uninstall log before retrying." "$uninstall_status"
  fi
  exit 0
fi

if safe_request "$UPDATE_REQUEST_FILE" "manual update"; then
  ACTIVE_ACTION="update"
  rm -f "$UPDATE_REQUEST_FILE"
  log "Starting queued manual update"
  write_update_progress "checking" "Checking the verified release feed."
  if ! /bin/bash -n "$RUNTIME_DIR/sls_mass_notify_update.sh" >> "$LOG_FILE" 2>&1; then
    fail_maintenance "update_script_syntax_error" "The installed updater contains a shell syntax error. No updater was executed." 2
  fi
  if SLS_MASS_NOTIFY_MANUAL_UPDATE=1 /usr/bin/timeout 1800 "$RUNTIME_DIR/sls_mass_notify_update.sh" >> "$LOG_FILE" 2>&1; then
    if ! update_progress_is complete; then
      fail_maintenance "process_failed" "The update process exited without confirming completion. Review Notification Logs for details."
    fi
  else
    update_status=$?
    update_category="process_failed"
    [ "$update_status" -ne 124 ] || update_category="timeout"
    fail_maintenance "$update_category" "The update process failed. Review Notification Logs for details." "$update_status"
  fi
  log "Queued manual update completed successfully"
  exit 0
fi

[ -e "$REQUEST_FILE" ] || exit 0
if [ -L "$REQUEST_FILE" ] || [ ! -f "$REQUEST_FILE" ]; then
  log "Rejected unsafe installation repair request marker"
  rm -f "$REQUEST_FILE"
  exit 1
fi
owner="$(stat -c '%U' "$REQUEST_FILE" 2>/dev/null || true)"
if [ "$owner" != "asterisk" ] && [ "$owner" != "root" ]; then
  log "Rejected installation repair request owned by $owner"
  rm -f "$REQUEST_FILE"
  exit 1
fi
rm -f "$REQUEST_FILE"

log "Starting queued installation repair"
ACTIVE_ACTION="repair"
write_maintenance_progress "repair" "running" "Refreshing runtime files, permissions, dialplan, dashboard integration, and local signatures."
if ! /usr/bin/python3 "$RUNTIME_DIR/sls_config.py" "$CONFIG_FILE" >/dev/null 2>>"$LOG_FILE"; then
  fail_maintenance "protected_config_validation_failed" "The protected central configuration is invalid or unavailable. Repair was not started." 2
fi
if ! acquire_mutation_coordination >> "$LOG_FILE" 2>&1; then
  fail_maintenance "active_delivery_or_configuration" "Repair did not start because notifications, paging, configuration editing, or collector health could not be confirmed idle. Allow current activity to finish and retry; no delivery was interrupted." 2
fi
# Root operations use only the immutable publisher generation. All FreePBX
# bootstrap, module hooks, DB access and reload hooks run as asterisk.
repair_ok=1
repair_status=0
for phase in prepare dependencies; do
  if [ "$repair_ok" -eq 1 ]; then
    run_without_maintenance_lock /usr/bin/python3 -I "$PRIVILEGED_HELPER" "$phase" --apply >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
  fi
done
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/env SLS_MASS_NOTIFY_DEFER_SIGNING=1 /usr/bin/php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->installUnprivilegedPhase();' >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/python3 -I "$PRIVILEGED_HELPER" activate --apply >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/env SLS_MASS_NOTIFY_PRESERVE_PENDING=1 /usr/sbin/fwconsole reload >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  for module in slsmassnotifyserver dashboard framework; do
    [ -d "/var/www/html/admin/modules/$module" ] || continue
    run_without_maintenance_lock "$SIGNER" "$module" >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
  done
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/bin/python3 -I "$PRIVILEGED_HELPER" verify >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/timeout 300 /usr/bin/php -r '
require "/etc/freepbx.conf";
$obj = \FreePBX::Create()->Slsmassnotifyserver;
$obj->verifyUnprivilegedIntegration();
' >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  verify_mutation_idle >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
fi
if [ "$repair_ok" -eq 1 ]; then
  run_without_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->finalizeVerifiedUnprivilegedRestore();' >> "$LOG_FILE" 2>&1 || { repair_status=$?; repair_ok=0; }
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
