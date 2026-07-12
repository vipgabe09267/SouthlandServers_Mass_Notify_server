#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -euo pipefail

umask 027

REQUEST_FILE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/repair.request"
RUNTIME_DIR="/usr/local/bin/sls_mass_notify"
SIGNER="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
LOG_FILE="/var/log/sls_mass_notify.log"
LOCK_FILE="/run/lock/sls-mass-notify-maintenance.lock"

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

[ "${EUID:-$(id -u)}" -eq 0 ] || exit 1
exec 9>"$LOCK_FILE"
chmod 0600 "$LOCK_FILE"
flock -n 9 || exit 0

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
