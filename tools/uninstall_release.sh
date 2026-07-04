#!/usr/bin/env bash
set -euo pipefail

MODULE="${SLS_MASS_NOTIFY_MODULE:-slsmassnotifyserver}"
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
CONFIG_TMP=""

log() {
  printf '%s\n' "$*"
}

require_freepbx() {
  command -v fwconsole >/dev/null || {
    log "fwconsole not found. Run this inside the FreePBX machine."
    exit 1
  }
  [ -d /var/www/html/admin/modules ] || {
    log "/var/www/html/admin/modules not found. This does not look like a FreePBX server."
    exit 1
  }
}

preserve_config() {
  [ -d "$DATA_DIR" ] || return 0
  CONFIG_TMP="$(mktemp -d /tmp/slsmassnotifyserver-config.XXXXXX)"
  for name in \
    mass-notifications.config \
    mass-notifications.pending.config \
    mass-notifications.conf \
    mass-notifications-settings.json \
    mass-notifications-settings.pending.json
  do
    [ -f "$DATA_DIR/$name" ] && cp -p "$DATA_DIR/$name" "$CONFIG_TMP/$name"
  done
  find "$DATA_DIR" -maxdepth 1 -type f \( -name '*.config' -o -name '*.conf' \) -exec cp -p {} "$CONFIG_TMP/" \; 2>/dev/null || true
}

restore_config() {
  [ -n "$CONFIG_TMP" ] && [ -d "$CONFIG_TMP" ] || return 0
  if find "$CONFIG_TMP" -type f | grep -q .; then
    mkdir -p "$DATA_DIR"
    cp -p "$CONFIG_TMP"/* "$DATA_DIR/" 2>/dev/null || true
    chown -R asterisk:asterisk "$DATA_DIR" 2>/dev/null || true
    chmod 0750 "$DATA_DIR" 2>/dev/null || true
    find "$DATA_DIR" -maxdepth 1 -type f -exec chmod 0640 {} + 2>/dev/null || true
  fi
  rm -rf "$CONFIG_TMP"
}

remove_menu_patch() {
  local path="/var/www/html/admin/views/menu_items.php"
  [ -w "$path" ] || return 0
  python3 - "$path" <<'PY'
import sys
path = sys.argv[1]
with open(path, "r", encoding="utf-8", errors="ignore") as handle:
    data = handle.read()
start = "\t// SLS Mass Notifications menu placement: keep Mass Notifications after UCP/User Panel.\n"
needle = "\telse if ($a == 'other')\n\t\treturn 1;\n"
idx = data.find(start)
if idx != -1:
    end = data.find(needle, idx)
    if end != -1:
        data = data[:idx] + data[end:]
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
  if command -v a2disconf >/dev/null; then
    a2disconf sls-mass-notify >/dev/null 2>&1 || true
  fi
  rm -f /etc/apache2/conf-enabled/sls-mass-notify.conf
  rm -f /etc/apache2/conf-available/sls-mass-notify.conf
  rm -f /var/lib/apache2/conf/enabled_by_admin/sls-mass-notify
  systemctl reload apache2 >/dev/null 2>&1 || true
}

remove_piper_wrapper() {
  local wrapper="/usr/local/bin/piper"
  local piper_bin="$DATA_DIR/piper/venv/bin/piper"
  if [ -L "$wrapper" ] && [ "$(readlink "$wrapper")" = "$piper_bin" ]; then
    rm -f "$wrapper"
  elif [ -f "$wrapper" ] && grep -q "$piper_bin" "$wrapper" 2>/dev/null; then
    rm -f "$wrapper"
  fi
}

remove_runtime_files() {
  rm -rf /usr/local/bin/sls_mass_notify
  remove_piper_wrapper
  rm -f /usr/local/sbin/sign_sls_mass_notify_local_sig.sh
  rm -f /usr/local/sbin/sign_sls_mass_notify_local_sig.sh.bak-*
  rm -rf /root/.gnupg-sls-mass-notify
  rm -f /etc/freepbx.secure/slsmassnotifyserver.sig
  rm -rf /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin
  rm -rf /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin
  rm -f /var/lib/asterisk/bin/sls_mass_notify
  rm -f /var/lib/asterisk/bin/sls_mass_notify_test.sh
  rm -rf /var/www/html/api/sls-mass-notify
  rm -rf /var/www/html/api/sipnotify
  rm -rf /var/www/html/sls_mass_notify
  rm -rf /etc/asterisk/slsmassnotify
  rm -rf "$DATA_DIR"
  rm -f /tmp/slsmassnotifyserver-*.tgz
  rm -f /tmp/slsmassnotifyserver-install.log
  rm -f /tmp/sls-install.sh /tmp/sls-install.sh.bak-*
  rm -f /tmp/sls-uninstall.sh /tmp/sls-uninstall.sh.bak-*
}

remove_cron() {
  crontab -l 2>/dev/null | grep -v 'sls_mass_notify_nws_poll.sh' | crontab - 2>/dev/null || true
}

main() {
  require_freepbx
  preserve_config
  fwconsole ma uninstall "$MODULE" >/dev/null 2>&1 || true
  fwconsole ma delete "$MODULE" >/dev/null 2>&1 || true
  rm -rf "/var/www/html/admin/modules/$MODULE"
  remove_cron
  remove_menu_patch
  remove_managed_block /etc/asterisk/extensions_custom.conf "SLS Mass Notifications Dialplan"
  remove_managed_block /etc/asterisk/manager_custom.conf "SLS Mass Notifications AMI"
  disable_apache_conf
  remove_runtime_files
  restore_config
  asterisk -rx "dialplan reload" >/dev/null 2>&1 || true
  asterisk -rx "manager reload" >/dev/null 2>&1 || true
  fwconsole ma refreshsignatures >/dev/null 2>&1 || true
  fwconsole reload
  log "SLS Mass Notify uninstall cleanup finished. Central config files were preserved when present."
}

main "$@"
