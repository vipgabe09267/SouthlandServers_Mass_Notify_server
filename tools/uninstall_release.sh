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
UNINSTALL_MAINTENANCE_LOCK_FD=""
STOCK_RESTORE_LOG="/tmp/slsmassnotifyserver-uninstall-stock-modules.log"

log() {
  printf '%s\n' "$*"
}

require_freepbx() {
	local required_command
	  [ "${EUID:-$(id -u)}" -eq 0 ] || {
    log "Run the uninstaller as root."
    exit 1
  }
	  for required_command in /usr/sbin/fwconsole /usr/sbin/asterisk /usr/bin/php /usr/bin/python3 /usr/bin/gpg /usr/bin/crontab /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/bin/base64 /usr/bin/sha256sum /usr/bin/systemctl /usr/bin/su; do
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
  php -d pcre.jit=0 -r '
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
  install -d -m 0755 -o root -g root "$(dirname "$lock_file")"
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

  exec {UNINSTALL_MAINTENANCE_LOCK_FD}>"$lock_file"
  chown root:root "$lock_file"
  chmod 0600 "$lock_file"
  flock -w 120 "$UNINSTALL_MAINTENANCE_LOCK_FD" || {
    log "Another Mass Notify maintenance, update, repair, or uninstall operation is still running."
    return 1
  }
}

snapshot_local_signer() {
  local candidate=""
  local -a candidates=()

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
    # Older releases did not have the transactional signer. They retain the
    # compatibility fallback below; current releases snapshot the robust helper
    # before the FreePBX uninstall hook removes both original copies.
    grep -Fq 'publish_candidate_transactionally' "$candidate" \
      && grep -Fq 'acquire_signing_lock' "$candidate" || continue
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
    return 0
  done
  return 0
}

cleanup_recovery_signer() {
  if [ -n "$RECOVERY_SIGNER_DIR" ] && [ -d "$RECOVERY_SIGNER_DIR" ]; then
    find "$RECOVERY_SIGNER_DIR" -depth -delete 2>/dev/null || true
  fi
  RECOVERY_SIGNER_DIR=""
  RECOVERY_SIGNER=""
}

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
  # PCRE JIT allocation can be denied by otherwise healthy unprivileged LXC
  # containers. Limit this compatibility override to the uninstall process.
  php -d pcre.jit=0 "$(command -v fwconsole)" "$@"
)

module_registry_exists() {
  SLS_MODULE_NAME="$MODULE" php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$statement = \FreePBX::Database()->prepare("SELECT COUNT(*) FROM modules WHERE modulename = ?");
$statement->execute([(string)getenv("SLS_MODULE_NAME")]);
exit((int)$statement->fetchColumn() > 0 ? 0 : 1);
'
}

remove_module_registration() {
  if module_registry_exists; then
    if ! run_fwconsole ma uninstall "$MODULE" >/tmp/slsmassnotifyserver-uninstall-module.log 2>&1; then
      log "FreePBX reported an error while uninstalling $MODULE; checking whether its registry row was removed."
    fi
  fi
  if module_registry_exists; then
    run_fwconsole ma disable "$MODULE" >>/tmp/slsmassnotifyserver-uninstall-module.log 2>&1 || true
    run_fwconsole ma delete "$MODULE" >>/tmp/slsmassnotifyserver-uninstall-module.log 2>&1 || true
  fi
  if module_registry_exists; then
    log "FreePBX left the $MODULE registry row behind; removing that single stale row before deleting module files."
    SLS_MODULE_NAME="$MODULE" php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$statement = \FreePBX::Database()->prepare("DELETE FROM modules WHERE modulename = ?");
$statement->execute([(string)getenv("SLS_MODULE_NAME")]);
exit(0);
'
  fi
  if module_registry_exists; then
    log "Unable to remove the $MODULE registry row. Module files were not deleted."
    return 1
  fi
  rm -rf "/var/www/html/admin/modules/$MODULE"
}

capture_signing_fingerprint() {
  [ -d "$SIGN_HOME" ] || return 0
  SIGNING_FINGERPRINT="$(GNUPGHOME="$SIGN_HOME" gpg --batch --with-colons --list-keys 2>/dev/null | awk -F: '/^fpr:/ {print $10; exit}')"
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
  python3 - "$path" <<'PY'
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
  python3 - "$path" <<'PY'
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
  python3 - "$path" "$name" <<'PY'
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
  SLS_CONFIG="$DATA_DIR/mass-notifications.config" php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$candidates = ['slsmassnotify', 'sls_mass_notify', 'nws_push'];
$path = (string)getenv('SLS_CONFIG');
if ($path !== '' && is_readable($path)) {
    $settings = json_decode((string)file_get_contents($path), true);
    $configured = is_array($settings) ? (string)($settings['ami']['username'] ?? '') : '';
    if ($configured !== '') {
        $candidates[] = $configured;
    }
}
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
  SLS_DATA_DIR="$DATA_DIR" php -d pcre.jit=0 <<'PHP'
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
	  if module_registry_exists; then
	    log "Uninstall verification failed; the $MODULE FreePBX registry row remains."
	    return 1
	  fi
  SLS_CONFIG="$DATA_DIR/mass-notifications.config" php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$candidates = ['slsmassnotify', 'sls_mass_notify', 'nws_push'];
$path = (string)getenv('SLS_CONFIG');
if ($path !== '' && is_readable($path)) {
    $settings = json_decode((string)file_get_contents($path), true);
    $configured = is_array($settings) ? (string)($settings['ami']['username'] ?? '') : '';
    if ($configured !== '') {
        $candidates[] = $configured;
    }
}
$candidates = array_values(array_unique(array_filter($candidates)));
$database = \FreePBX::Database();
$statement = $database->prepare('SELECT COUNT(*) FROM manager WHERE name = ?');
foreach ($candidates as $username) {
    $statement->execute([$username]);
    if ((int)$statement->fetchColumn() > 0) {
        fwrite(STDERR, "FreePBX AMI user remains after uninstall: {$username}\n");
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
    /usr/local/bin/sls_mass_notify \
    /usr/local/sbin/sign_sls_mass_notify_local_sig.sh \
    /var/www/html/api/sipnotify \
    /var/www/html/api/sls-mass-notify \
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
  if crontab -l 2>/dev/null | grep -Eq 'sls_mass_notify_(update|maintenance)\.sh' \
    || crontab -u asterisk -l 2>/dev/null | grep -Eq 'sls_mass_notify_(weather_poll|nws_poll)\.sh|sls_mass_notify_schedule_worker\.php'; then
    log "Uninstall verification failed; an SLS scheduled job remains."
    return 1
  fi
  if grep -Fq 'SLS Mass Notifications menu placement:' /var/www/html/admin/views/menu_items.php 2>/dev/null; then
    log "Uninstall verification failed; the managed FreePBX menu placement remains."
    return 1
  fi
  return 0
}

verify_stock_module() {
  local stock_module="$1"
  SLS_VERIFY_MODULE="$stock_module" php -d pcre.jit=0 -r '
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

redownload_stock_module() {
  local stock_module="$1"
  : > "$STOCK_RESTORE_LOG"
  if run_fwconsole ma --no-interaction --ignorecache --stable -f downloadinstall "$stock_module" >>"$STOCK_RESTORE_LOG" 2>&1; then
    return 0
  fi
  # Some FreePBX 17 builds reject --stable while still accepting the same
  # forced fresh-cache download through the default configured repository.
  run_fwconsole ma --no-interaction --ignorecache -f downloadinstall "$stock_module" >>"$STOCK_RESTORE_LOG" 2>&1
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
    if ! SLS_LOCAL_SIGN_HOME="$SIGN_HOME" \
      SLS_LOCAL_SIGN_LOCK="/run/lock/sls-mass-notify-signing.lock" \
      /usr/bin/timeout --signal=TERM 360 "$RECOVERY_SIGNER" "$stock_module" \
        >>"$STOCK_RESTORE_LOG" 2>&1; then
      log "The protected local signer could not verify the cleaned $stock_module module."
      return 1
    fi
    verify_stock_module "$stock_module" || return 1
    return 0
  fi

  log "Using the legacy local-signing compatibility path for $stock_module."
  [ -d "$module_dir" ] || return 1
  command -v gpg >/dev/null || return 1
  workdir="$(mktemp -d "/tmp/${stock_module}-sls-uninstall-sign.XXXXXX")"
  chmod 0700 "$workdir"
  signedby="Southland Servers Mass Notifications Uninstall Recovery <root@$(hostname -f 2>/dev/null || hostname)>"

  install -d -m 0700 "$SIGN_HOME"
  if ! GNUPGHOME="$SIGN_HOME" gpg --batch --list-secret-keys --with-colons 2>/dev/null | grep -q '^sec:'; then
    {
      printf '%s\n' 'Key-Type: RSA'
      printf '%s\n' 'Key-Length: 3072'
      printf '%s\n' 'Name-Real: Southland Servers Mass Notifications Uninstall Recovery'
      printf 'Name-Email: root@%s\n' "$(hostname -f 2>/dev/null || hostname)"
      printf '%s\n' 'Expire-Date: 0'
      printf '%s\n' '%no-protection'
      printf '%s\n' '%commit'
    } > "$workdir/keyparams"
    GNUPGHOME="$SIGN_HOME" gpg --batch --generate-key "$workdir/keyparams" >/dev/null 2>&1 || {
      rm -rf "$workdir"
      return 1
    }
  fi

  keyid="$(GNUPGHOME="$SIGN_HOME" gpg --batch --list-secret-keys --with-colons 2>/dev/null | awk -F: '/^sec:/ {print $5; exit}')"
  fingerprint="$(GNUPGHOME="$SIGN_HOME" gpg --batch --list-secret-keys --with-colons 2>/dev/null | awk -F: '/^fpr:/ {print $10; exit}')"
  if [ -z "$keyid" ] || [ -z "$fingerprint" ]; then
    rm -rf "$workdir"
    return 1
  fi
  SIGNING_FINGERPRINT="$fingerprint"

  {
    printf '%s\n' ';################################################'
    printf '%s\n' ';#        FreePBX Module Signature File         #'
    printf '%s\n' ';################################################'
    printf '%s\n' ';# Do not alter the contents of this file!  If  #'
    printf '%s\n' ';# this file is tampered with, the module will  #'
    printf '%s\n' ';# fail validation and be marked as invalid!    #'
    printf '%s\n' ';################################################'
    printf '%s\n' '[config]'
    printf '%s\n' 'version=1'
    printf '%s\n' 'hash=sha256'
    printf 'signedwith=%s\n' "$keyid"
    printf "signedby='%s'\n" "$signedby"
    printf '%s\n' 'repo=local'
    php -r 'printf("timestamp=%.4f\n", microtime(true));'
    printf '%s\n' '[hashes]'
    cd "$module_dir"
    find . -type f ! -name 'module.sig' ! -name '*.pyc' ! -path '*/__pycache__/*' -printf '%P\n' \
      | LC_ALL=C sort \
      | while IFS= read -r relative_file; do
          printf '%s = %s\n' "$relative_file" "$(sha256sum "$relative_file" | awk '{print $1}')"
        done
  } > "$workdir/module.plain"

  GNUPGHOME="$SIGN_HOME" gpg --batch --yes --pinentry-mode loopback --passphrase '' \
    --local-user "$keyid" --clearsign --output "$workdir/module.sig" "$workdir/module.plain" >/dev/null 2>&1 || {
      rm -rf "$workdir"
      return 1
    }
  install -m 0644 -o asterisk -g asterisk "$workdir/module.sig" "$module_dir/module.sig"
  GNUPGHOME="$SIGN_HOME" gpg --batch --armor --export "$keyid" > "$workdir/public.asc"

  freepbx_gpg_home="$(php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$gpg = \FreePBX::GPG();
$reflection = new ReflectionClass($gpg);
if ($reflection->hasMethod("getGpgLocation")) {
    $method = $reflection->getMethod("getGpgLocation");
    $method->setAccessible(true);
    echo (string)$method->invoke($gpg);
} else {
    echo "/var/lib/asterisk/.gnupg";
}
exit(0);
')"
  [ -n "$freepbx_gpg_home" ] || freepbx_gpg_home="/var/lib/asterisk/.gnupg"
  install -d -m 0700 -o asterisk -g asterisk "$freepbx_gpg_home"
  timeout 30 su -s /bin/bash asterisk -c "gpg --homedir '$freepbx_gpg_home' --batch --import" \
    < "$workdir/public.asc" >/dev/null 2>&1 || {
      rm -rf "$workdir"
      return 1
    }
  printf '%s:6:\n' "$fingerprint" \
    | timeout 30 su -s /bin/bash asterisk -c "gpg --homedir '$freepbx_gpg_home' --batch --import-ownertrust" >/dev/null 2>&1 || {
      rm -rf "$workdir"
      return 1
    }
  chown -R asterisk:asterisk "$freepbx_gpg_home" 2>/dev/null || true
  chmod 0700 "$freepbx_gpg_home" 2>/dev/null || true
  rm -rf "$workdir"
  verify_stock_module "$stock_module"
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
  rm -rf /var/www/html/sls_mass_notify
  rm -rf /etc/asterisk/slsmassnotify
  rm -rf "$DATA_DIR"
  rm -f /var/log/sls_mass_notify.log
  rm -f /var/log/sls_mass_notify_events.jsonl
  rm -f /var/log/sls_mass_notify_push.log
  rm -f /tmp/slsmassnotifyserver-*.tgz
  rm -f /tmp/slsmassnotifyserver-install.log
  rm -f /tmp/sls-install.sh /tmp/sls-install.sh.bak-*
  rm -f /tmp/sls-uninstall.sh /tmp/sls-uninstall.sh.bak-*
  rm -rf /tmp/sls-mass-notify-*
  rm -f /var/tmp/nws_last_clear.ts
}

remove_cron() {
  local user
  local tmp
  for user in root asterisk; do
    tmp="$(mktemp /tmp/slsmassnotifyserver-cron.XXXXXX)"
    crontab -u "$user" -l 2>/dev/null \
      | grep -vE 'sls_mass_notify_(weather_poll|nws_poll|update|maintenance)\.sh|sls_mass_notify_schedule_worker\.php|nwsalerts_ensure_menu_patch\.sh' > "$tmp" || true
    crontab -u "$user" "$tmp" 2>/dev/null || true
    rm -f "$tmp"
  done
}

restore_stock_modules() {
  rm -f /var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php
  rm -f /var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php
	  remove_legacy_dashboard_patch
  for stock_module in dashboard framework; do
	    [ -d "/var/www/html/admin/modules/$stock_module" ] || continue
	    if grep -q '^repo=local$' "/var/www/html/admin/modules/$stock_module/module.sig" 2>/dev/null || \
	      ! verify_stock_module "$stock_module"; then
	      log "Restoring the stock FreePBX $stock_module module."
	      if redownload_stock_module "$stock_module" && verify_stock_module "$stock_module"; then
	        log "Restored and verified the stock FreePBX $stock_module module."
	      elif locally_sign_stock_module "$stock_module"; then
	        KEEP_SIGNING_TRUST=1
	        log "Warning: the FreePBX repository was unavailable for $stock_module. The cleaned module was locally signed and verified so the FreePBX UI remains usable."
	        log "When repository access returns, run: fwconsole ma --ignorecache -f downloadinstall $stock_module"
	      else
	        log "Unable to restore or locally verify the FreePBX $stock_module module. Details: $STOCK_RESTORE_LOG"
	        return 1
	      fi
    fi
  done
}

refresh_dashboard_hook_index() {
  php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$hooksFile = '/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php';
if (!is_readable($hooksFile)) {
    fwrite(STDERR, "Dashboard hook loader is missing after uninstall.\n");
    exit(1);
}
require_once $hooksFile;
$dashboard = \FreePBX::Dashboard();
$visualOrder = $dashboard->getConfig('visualorder');
$hooks = \DashboardHooks::genHooks(is_array($visualOrder) ? $visualOrder : []);
foreach ((array)$hooks as $page) {
    foreach ((array)($page['entries'] ?? []) as $entry) {
        if (($entry['rawname'] ?? '') === 'SlsMassNotifyAnnouncement') {
            fwrite(STDERR, "Removed Mass Notify Dashboard hook is still discoverable.\n");
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
    # A repository outage forced a local signature fallback. Delete the private
    # signing material, but retain the public key FreePBX needs to verify the
    # cleaned stock modules until the administrator redownloads vendor copies.
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

main() {
  require_freepbx
  acquire_maintenance_coordination
  trap restore_user_data_on_exit EXIT
  snapshot_local_signer
  capture_signing_fingerprint
  preserve_user_data
	  remove_freepbx_manager_users
	  remove_module_registration
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
  remove_runtime_files
  restore_user_data
  remove_trusted_signing_key
  asterisk -rx "dialplan reload" >/dev/null 2>&1 || true
  asterisk -rx "module reload res_pjsip_notify.so" >/dev/null 2>&1 || true
  asterisk -rx "manager reload" >/dev/null 2>&1 || true
	  run_fwconsole reload
	  remove_freepbx_manager_users
	  asterisk -rx "manager reload" >/dev/null 2>&1 || true
	  verify_freepbx_cleanup
  cleanup_recovery_signer
  trap - EXIT
  if [ "$PURGE_CONFIG" = "1" ]; then
    log "SLS Mass Notify uninstall cleanup finished. Configuration and user data were purged."
  else
    log "SLS Mass Notify uninstall cleanup finished. Central config, config backups, and uploaded tones were preserved."
  fi
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
