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
RECOVERY_SIGNER_DIR=""
RECOVERY_SIGNER=""
UNINSTALL_MAINTENANCE_LOCK_FD=""
STOCK_RESTORE_LOG="/tmp/slsmassnotifyserver-uninstall-stock-modules.log"

log() {
  printf '%s\n' "$*"
}

require_freepbx() {
  [ "${EUID:-$(id -u)}" -eq 0 ] || {
    log "Run the uninstaller as root."
    exit 1
  }
  command -v fwconsole >/dev/null || {
    log "fwconsole not found. Run this inside the FreePBX machine."
    exit 1
  }
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

remove_bundled_system_recordings() {
  php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$filenames = [
    'custom/Paging_Tone_Opening',
    'custom/Paging_Tone_Closing',
    'custom/NWS_alert',
    'custom/Lightning_alert',
];
$database = \FreePBX::Database();
$statement = $database->prepare('DELETE FROM recordings WHERE filename = ?');
foreach ($filenames as $filename) {
    $statement->execute([$filename]);
}
exit(0);
PHP
  rm -f \
    /var/lib/asterisk/sounds/en/custom/Paging_Tone_Opening.wav \
    /var/lib/asterisk/sounds/en/custom/Paging_Tone_Closing.wav \
    /var/lib/asterisk/sounds/en/custom/NWS_alert.wav \
    /var/lib/asterisk/sounds/en/custom/Lightning_alert.wav
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
  php -d pcre.jit=0 <<'PHP'
<?php
$bootstrap_settings = ['freepbx_auth' => false, 'skip_astman' => true];
require '/etc/freepbx.conf';
$filenames = [
    'custom/Paging_Tone_Opening',
    'custom/Paging_Tone_Closing',
    'custom/NWS_alert',
    'custom/Lightning_alert',
];
$database = \FreePBX::Database();
$statement = $database->prepare('SELECT COUNT(*) FROM recordings WHERE filename = ?');
foreach ($filenames as $filename) {
    $statement->execute([$filename]);
    if ((int)$statement->fetchColumn() > 0) {
        fwrite(STDERR, "Bundled System Recording remains after uninstall: {$filename}\n");
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
    /var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php \
    /var/lib/asterisk/sounds/en/custom/Paging_Tone_Opening.wav \
    /var/lib/asterisk/sounds/en/custom/Paging_Tone_Closing.wav \
    /var/lib/asterisk/sounds/en/custom/NWS_alert.wav \
    /var/lib/asterisk/sounds/en/custom/Lightning_alert.wav; do
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
    || crontab -u asterisk -l 2>/dev/null | grep -Eq 'sls_mass_notify_(weather_poll|nws_poll)\.sh'; then
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
