#!/usr/bin/env bash
set -euo pipefail

umask 027

MODULE="${SLS_MASS_NOTIFY_MODULE:-slsmassnotifyserver}"
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
SIGN_HOME="/root/.gnupg-sls-mass-notify"
PURGE_CONFIG="${SLS_MASS_NOTIFY_PURGE_CONFIG:-0}"
CONFIG_TMP=""
SIGNING_FINGERPRINT=""
KEEP_SIGNING_TRUST=0
STOCK_RESTORE_LOG="/tmp/slsmassnotifyserver-uninstall-stock-modules.log"

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

run_fwconsole() {
  # PCRE JIT allocation can be denied by otherwise healthy unprivileged LXC
  # containers. Limit this compatibility override to the uninstall process.
  php -d pcre.jit=0 "$(command -v fwconsole)" "$@"
}

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
  [ -d "$DATA_DIR" ] || return 0
  CONFIG_TMP="$(mktemp -d /tmp/slsmassnotifyserver-config.XXXXXX)"
  for name in mass-notifications.config mass-notifications.pending.config; do
    [ -f "$DATA_DIR/$name" ] && cp -p "$DATA_DIR/$name" "$CONFIG_TMP/$name"
  done
  [ -d "$DATA_DIR/config-backups" ] && cp -a "$DATA_DIR/config-backups" "$CONFIG_TMP/config-backups"
  # Uploaded tones are binary user data referenced by the central config.
  [ -d "$DATA_DIR/sounds/tones" ] && {
    mkdir -p "$CONFIG_TMP/sounds"
    cp -a "$DATA_DIR/sounds/tones" "$CONFIG_TMP/sounds/tones"
  }
}

restore_user_data() {
  [ -n "$CONFIG_TMP" ] && [ -d "$CONFIG_TMP" ] || return 0
  if find "$CONFIG_TMP" -type f -print -quit | grep -q .; then
    mkdir -p "$DATA_DIR"
    cp -a "$CONFIG_TMP"/. "$DATA_DIR/"
    chown -R asterisk:asterisk "$DATA_DIR" 2>/dev/null || true
    chmod 0750 "$DATA_DIR" 2>/dev/null || true
    [ -d "$DATA_DIR/config-backups" ] && chmod 0750 "$DATA_DIR/config-backups" 2>/dev/null || true
    [ -d "$DATA_DIR/sounds" ] && chmod 0755 "$DATA_DIR/sounds" 2>/dev/null || true
    [ -d "$DATA_DIR/sounds/tones" ] && chmod 0755 "$DATA_DIR/sounds/tones" 2>/dev/null || true
    find "$DATA_DIR" -maxdepth 1 -type f -name '*.config' -exec chmod 0640 {} + 2>/dev/null || true
    find "$DATA_DIR/config-backups" -type f -exec chmod 0640 {} + 2>/dev/null || true
    find "$DATA_DIR/sounds/tones" -type f -name '*.wav' -exec chmod 0644 {} + 2>/dev/null || true
  fi
  rm -rf "$CONFIG_TMP"
  CONFIG_TMP=""
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
    r"\$final\[\$i\]\['title'\]\s*=\s*_\(\"Mass Notifications Plugin\"\);\s*\$i\+\+;\s*",
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
    /etc/asterisk/slsmassnotify; do
    if [ -e "$artifact" ] || [ -L "$artifact" ]; then
      log "Uninstall verification failed; managed artifact remains: $artifact"
      return 1
    fi
  done
}

verify_stock_module() {
  local stock_module="$1"
  SLS_VERIFY_MODULE="$stock_module" php -d pcre.jit=0 -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
$result = \FreePBX::GPG()->verifyModule((string)getenv("SLS_VERIFY_MODULE"));
$status = (int)($result["status"] ?? 0);
exit(($status & 129) === 129 ? 0 : 1);
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
  rm -rf /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin
  rm -rf /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin
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
      | grep -vE 'sls_mass_notify_(weather_poll|nws_poll|update|maintenance)\.sh|nwsalerts_ensure_menu_patch\.sh' > "$tmp" || true
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
  capture_signing_fingerprint
  preserve_user_data
	  remove_freepbx_manager_users
	  remove_module_registration
  remove_cron
  remove_menu_patch
	  remove_legacy_dashboard_patch
  remove_managed_block /etc/asterisk/sip_notify_custom.conf "SLS Mass Notifications SIP NOTIFY Templates"
  remove_managed_block /etc/asterisk/extensions_custom.conf "SLS Mass Notifications Dialplan"
  remove_managed_block /etc/asterisk/manager_custom.conf "SLS Mass Notifications AMI"
  disable_apache_conf
  restore_stock_modules
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
  if [ "$PURGE_CONFIG" = "1" ]; then
    log "SLS Mass Notify uninstall cleanup finished. Configuration and user data were purged."
  else
    log "SLS Mass Notify uninstall cleanup finished. Central config, config backups, and uploaded tones were preserved."
  fi
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
