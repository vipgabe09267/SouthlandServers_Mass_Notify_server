#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -euo pipefail

umask 027
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/repair.request"
UPDATE_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update.request"
UPDATE_PROGRESS_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-progress.json"
CONFIG_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config"
UNINSTALL_REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/uninstall.request"
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

secure_central_config() {
  [ -e "$CONFIG_FILE" ] || return 0
  if [ -L "$CONFIG_FILE" ] || [ ! -f "$CONFIG_FILE" ]; then
    log "Rejected unsafe protected central configuration path"
    return 1
  fi
  /bin/chown asterisk:asterisk "$CONFIG_FILE"
  /bin/chmod 0640 "$CONFIG_FILE"
}

[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1
exec 9>"$LOCK_FILE"
chmod 0600 "$LOCK_FILE"
flock -n 9 || exit 0
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
  if php -r 'require "/etc/freepbx.conf"; \FreePBX::Create()->Slsmassnotifyserver->repairUpdateSensitiveIntegration(); exit(0);' >> "$LOG_FILE" 2>&1; then
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

if safe_request "$UNINSTALL_REQUEST_FILE" "uninstall"; then
  rm -f "$UNINSTALL_REQUEST_FILE"
  log "Starting queued complete uninstall"
  SLS_MASS_NOTIFY_PURGE_CONFIG=1 "$RUNTIME_DIR/sls_mass_notify_uninstall.sh" >> "$LOG_FILE" 2>&1 || log "Queued complete uninstall reported an error"
  exit 0
fi

if safe_request "$UPDATE_REQUEST_FILE" "manual update"; then
  rm -f "$UPDATE_REQUEST_FILE"
  log "Starting queued manual update"
  write_update_progress "checking" "Checking the verified beta release feed."
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
php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >> "$LOG_FILE" 2>&1
fwconsole chown >> "$LOG_FILE" 2>&1
secure_central_config

chown -R root:root "$RUNTIME_DIR"
find "$RUNTIME_DIR" -type d -exec chmod 0755 {} +
find "$RUNTIME_DIR" -type f -exec chmod 0644 {} +
find "$RUNTIME_DIR/piper/venv/bin" -type f -exec chmod 0755 {} + 2>/dev/null || true
chmod 0755 "$RUNTIME_DIR"/*.sh "$RUNTIME_DIR"/*.py 2>/dev/null || true
chmod 0755 /usr/local/bin/piper 2>/dev/null || true

for module in slsmassnotifyserver dashboard framework; do
  [ -d "/var/www/html/admin/modules/$module" ] || continue
  "$SIGNER" "$module" >> "$LOG_FILE" 2>&1
done
fwconsole reload >> "$LOG_FILE" 2>&1
asterisk -rx "dialplan reload" >> "$LOG_FILE" 2>&1 || true
log "Queued installation repair completed"
