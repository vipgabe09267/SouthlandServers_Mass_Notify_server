#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -euo pipefail

umask 027
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/repair.request"
UPDATE_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update.request"
UPDATE_PROGRESS_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-progress.json"
MAINTENANCE_PROGRESS_FILE="/run/asterisk/sls-mass-notify-maintenance-progress.json"
CONFIG_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config"
UNINSTALL_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/uninstall.request"
INSTALL_FAILURE_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/install-failure.json"
RUNTIME_DIR="/usr/local/bin/sls_mass_notify"
SIGNER="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
LOG_FILE="/var/log/sls_mass_notify.log"
LOCK_FILE="/run/lock/sls-mass-notify-maintenance.lock"
MODULE_DIR="/var/www/html/admin/modules/slsmassnotifyserver"
DASHBOARD_DIR="/var/www/html/admin/modules/dashboard"
MENU_FILE="/var/www/html/admin/views/menu_items.php"

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

write_update_progress() {
  local state="$1"
  local message="$2"
  UPDATE_PROGRESS_FILE="$UPDATE_PROGRESS_FILE" UPDATE_STATE="$state" UPDATE_MESSAGE="$message" /usr/bin/python3 - <<'PY'
import json
import os
import pwd
import tempfile
from datetime import datetime, timezone

path = os.environ["UPDATE_PROGRESS_FILE"]
directory = os.path.dirname(path)
os.makedirs(directory, mode=0o750, exist_ok=True)
payload = {
    "state": os.environ["UPDATE_STATE"],
    "message": os.environ["UPDATE_MESSAGE"][:300],
    "updated_at": datetime.now(timezone.utc).isoformat(),
}
fd, temporary = tempfile.mkstemp(prefix=".update-progress.", dir=directory)
with os.fdopen(fd, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, separators=(",", ":"))
    handle.write("\n")
os.chmod(temporary, 0o640)
account = pwd.getpwnam("asterisk")
os.chown(temporary, account.pw_uid, account.pw_gid)
os.replace(temporary, path)
PY
}

write_maintenance_progress() {
  local action="$1"
  local state="$2"
  local message="$3"
  MAINTENANCE_PROGRESS_FILE="$MAINTENANCE_PROGRESS_FILE" MAINTENANCE_ACTION="$action" MAINTENANCE_STATE="$state" MAINTENANCE_MESSAGE="$message" /usr/bin/python3 - <<'PY'
import json
import os
import pwd
import tempfile
from datetime import datetime, timezone

path = os.environ["MAINTENANCE_PROGRESS_FILE"]
directory = os.path.dirname(path)
os.makedirs(directory, mode=0o755, exist_ok=True)
payload = {
    "action": os.environ["MAINTENANCE_ACTION"],
    "state": os.environ["MAINTENANCE_STATE"],
    "message": os.environ["MAINTENANCE_MESSAGE"][:300],
    "updated_at": datetime.now(timezone.utc).isoformat(),
}
fd, temporary = tempfile.mkstemp(prefix=".maintenance-progress.", dir=directory)
with os.fdopen(fd, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, separators=(",", ":"))
    handle.write("\n")
os.chmod(temporary, 0o640)
account = pwd.getpwnam("asterisk")
os.chown(temporary, account.pw_uid, account.pw_gid)
os.replace(temporary, path)
PY
}

write_install_failure() {
  local stage="$1"
  local solution="$2"
  INSTALL_FAILURE_FILE="$INSTALL_FAILURE_FILE" INSTALL_FAILURE_STAGE="$stage" INSTALL_FAILURE_SOLUTION="$solution" /usr/bin/python3 - <<'PY'
import json
import os
import pwd
import tempfile
from datetime import datetime, timezone

path = os.environ["INSTALL_FAILURE_FILE"]
directory = os.path.dirname(path)
os.makedirs(directory, mode=0o750, exist_ok=True)
payload = {
    "version": 1,
    "failed_at": datetime.now(timezone.utc).isoformat(),
    "stage": os.environ["INSTALL_FAILURE_STAGE"][:80],
    "message": "SLS Mass Notify installation repair did not complete.",
    "solution": os.environ["INSTALL_FAILURE_SOLUTION"][:400],
    "log": "/var/log/sls_mass_notify.log",
}
fd, temporary = tempfile.mkstemp(prefix=".install-failure.", dir=directory)
with os.fdopen(fd, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, indent=2, sort_keys=True)
    handle.write("\n")
os.chmod(temporary, 0o640)
account = pwd.getpwnam("asterisk")
os.chown(temporary, account.pw_uid, account.pw_gid)
os.replace(temporary, path)
PY
  /usr/bin/php -r '
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
  /usr/bin/php -r '
require "/etc/freepbx.conf";
\FreePBX::Notifications()->delete("slsmassnotifyserver", "INSTALLFAILED");
exit(0);
' >/dev/null 2>&1 || true
}

secure_central_config() {
  [ -e "$CONFIG_FILE" ] || return 0
  if ! CONFIG_PATH="$CONFIG_FILE" /usr/bin/python3 - <<'PY'
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
    config_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
finally:
    os.close(parent_fd)
try:
    metadata = os.fstat(config_fd)
    if not stat.S_ISREG(metadata.st_mode):
        raise SystemExit(3)
    account = pwd.getpwnam("asterisk")
    os.fchmod(config_fd, 0o640)
    os.fchown(config_fd, account.pw_uid, account.pw_gid)
    verified = os.fstat(config_fd)
    if stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_uid != account.pw_uid or verified.st_gid != account.pw_gid:
        raise SystemExit(4)
finally:
    os.close(config_fd)
PY
  then
    log "Rejected unsafe protected central configuration path"
    return 1
  fi
}

[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1
MAINTENANCE_LOCK_FD=""
exec {MAINTENANCE_LOCK_FD}>"$LOCK_FILE"
chmod 0600 "$LOCK_FILE"
flock -n "$MAINTENANCE_LOCK_FD" || exit 0
secure_central_config

# Generated speech and composite audio are short-lived delivery artifacts.
find /var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts -maxdepth 1 -type f -name '*.wav' -mmin +15 -delete 2>/dev/null || true

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
if [ "$integration_drift" -eq 1 ]; then
  log "FreePBX update drift detected; restoring Mass Notify dashboard/menu integration"
  if SLS_MASS_NOTIFY_DEFER_SIGNING=1 php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->repairUpdateSensitiveIntegration(); exit(0);' >> "$LOG_FILE" 2>&1; then
    /usr/sbin/fwconsole chown >> "$LOG_FILE" 2>&1 || true
    secure_central_config
    for module in dashboard framework; do
      [ -d "/var/www/html/admin/modules/$module" ] || continue
      "$SIGNER" "$module" >> "$LOG_FILE" 2>&1 || log "Unable to refresh local signature for $module"
    done
    log "Mass Notify dashboard/menu integration restored after FreePBX update drift"
  else
    log "Automatic dashboard/menu integration repair failed; it will retry on the next maintenance run"
  fi
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
if [ -x "$RUNTIME_DIR/sls_system_notifications.py" ]; then
  if ! /usr/bin/timeout 20 /usr/bin/python3 "$RUNTIME_DIR/sls_system_notifications.py" >> "$LOG_FILE" 2>&1; then
    log "System/error email notification check failed; it will retry later"
  fi
fi

if safe_request "$UNINSTALL_REQUEST_FILE" "uninstall"; then
  rm -f "$UNINSTALL_REQUEST_FILE"
  log "Starting queued complete uninstall"
  write_maintenance_progress "uninstall" "running" "Removing Mass Notify runtime, FreePBX integration, APIs, logs, and protected configuration."
  if SLS_MASS_NOTIFY_PURGE_CONFIG=1 "$RUNTIME_DIR/sls_mass_notify_uninstall.sh" >> "$LOG_FILE" 2>&1; then
    rm -f "$MAINTENANCE_PROGRESS_FILE"
  else
    log "Queued complete uninstall reported an error"
    write_maintenance_progress "uninstall" "failed" "Complete uninstall reported an error. Review the PBX console and uninstall log before retrying."
  fi
  exit 0
fi

if safe_request "$UPDATE_REQUEST_FILE" "manual update"; then
  rm -f "$UPDATE_REQUEST_FILE"
  log "Starting queued manual update"
  write_update_progress "checking" "Checking the verified release feed."
  if ! SLS_MASS_NOTIFY_MANUAL_UPDATE=1 /usr/bin/timeout 1800 "$RUNTIME_DIR/sls_mass_notify_update.sh" >> "$LOG_FILE" 2>&1; then
    write_update_progress "failed" "The update process failed. Review Notification Logs for details."
  fi
  log "Queued manual update finished"
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
write_maintenance_progress "repair" "running" "Refreshing runtime files, permissions, dialplan, dashboard integration, and local signatures."
repair_ok=1
SLS_MASS_NOTIFY_DEFER_SIGNING=1 php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >> "$LOG_FILE" 2>&1 || repair_ok=0
if [ "$repair_ok" -eq 1 ]; then
  fwconsole chown >> "$LOG_FILE" 2>&1 || repair_ok=0
fi
if [ "$repair_ok" -eq 1 ]; then
  secure_central_config || repair_ok=0
fi
if [ "$repair_ok" -eq 1 ]; then
  chown -R root:root "$RUNTIME_DIR" || repair_ok=0
  find "$RUNTIME_DIR" -type d -exec chmod 0755 {} + || repair_ok=0
  find "$RUNTIME_DIR" -type f -exec chmod 0644 {} + || repair_ok=0
  find "$RUNTIME_DIR/piper/venv/bin" -type f -exec chmod 0755 {} + 2>/dev/null || true
  chmod 0755 "$RUNTIME_DIR"/*.sh "$RUNTIME_DIR"/*.py 2>/dev/null || true
  chmod 0755 /usr/local/bin/piper 2>/dev/null || true
fi
if [ "$repair_ok" -eq 1 ]; then
  fwconsole reload >> "$LOG_FILE" 2>&1 || repair_ok=0
  asterisk -rx "dialplan reload" >> "$LOG_FILE" 2>&1 || repair_ok=0
fi
if [ "$repair_ok" -eq 1 ]; then
  for module in slsmassnotifyserver dashboard framework; do
    [ -d "/var/www/html/admin/modules/$module" ] || continue
    "$SIGNER" "$module" >> "$LOG_FILE" 2>&1 || repair_ok=0
  done
fi
if [ "$repair_ok" -eq 1 ]; then
  # A successful command exit is not enough to clear a persistent failure.
  # Recheck signatures, generated dialplan, Asterisk capabilities and AMI,
  # runtime syntax/executability, Piper voices, cron, Apache/API and Dashboard
  # integration after the repair and signing steps have all completed.
  /usr/bin/timeout 300 /usr/bin/php -r '
require "/etc/freepbx.conf";
require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php";
$class = "\\FreePBX\\modules\\Slsmassnotifyserver";
$obj = new $class(\FreePBX::Create());
$obj->verifyProtectedRepairIntegration();
exit(0);
' >> "$LOG_FILE" 2>&1 || repair_ok=0
fi
if [ "$repair_ok" -eq 1 ]; then
  log "Queued installation repair completed"
  clear_install_failure
  write_maintenance_progress "repair" "complete" "Installation repair completed successfully."
else
  log "Queued installation repair failed"
  write_install_failure "protected repair" "Check the failed dependency, Asterisk capability, local signer, or FreePBX reload step in the maintenance log, correct it, and run Repair Installation again."
  write_maintenance_progress "repair" "failed" "Installation repair failed. Review Notification Logs before retrying."
  exit 1
fi
