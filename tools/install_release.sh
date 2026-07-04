#!/usr/bin/env bash
set -euo pipefail

MODULE="${SLS_MASS_NOTIFY_MODULE:-slsmassnotifyserver}"
TGZ="${SLS_MASS_NOTIFY_TGZ:-/tmp/slsmassnotifyserver-0.0.3-beta.tgz}"
URL="${SLS_MASS_NOTIFY_TGZ_URL:-${1:-}}"
SHA256="${SLS_MASS_NOTIFY_SHA256:-}"
TOKEN="${SLS_MASS_NOTIFY_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
LOG_FILE="${SLS_MASS_NOTIFY_INSTALL_LOG:-/tmp/slsmassnotifyserver-install.log}"
EXPECTED_TGZ_SHA256="79051f5fcbb209c03673ee9be47164d41b2a830d6803d91ce2fdfa8e58fab3fa"

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

install_dependencies() {
  if command -v apt-get >/dev/null; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y curl wget python3 python3-venv python3-pip sox tar
  else
    command -v curl >/dev/null || command -v wget >/dev/null || {
      log "curl or wget is required to download the module."
      exit 1
    }
    command -v python3 >/dev/null || {
      log "python3 is required."
      exit 1
    }
    command -v sox >/dev/null || {
      log "sox is required."
      exit 1
    }
  fi
}

preflight_python() {
  command -v python3 >/dev/null || {
    log "python3 is required."
    exit 1
  }
  python3 --version >/dev/null 2>&1 || {
    log "python3 is installed but not executable or broken."
    exit 1
  }
  python_path="$(command -v python3)"
  [ -x "$python_path" ] || {
    log "$python_path exists but is not executable."
    exit 1
  }
}

download_tgz() {
  if [ -n "$URL" ]; then
    rm -f "$TGZ"
    if command -v curl >/dev/null; then
      curl_args=(-fL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 900 -H "Accept: application/octet-stream")
      if [ -n "$TOKEN" ]; then
        curl_args+=(-H "Authorization: Bearer $TOKEN")
      fi
      curl "${curl_args[@]}" -o "$TGZ" "$URL"
    else
      wget --tries=5 --timeout=900 -O "$TGZ" "$URL"
    fi
  fi

  [ -s "$TGZ" ] || {
    log "$TGZ is missing. Set SLS_MASS_NOTIFY_TGZ_URL or upload the TGZ to this path first."
    exit 1
  }
}

verify_tgz() {
  actual_sha="$(sha256sum "$TGZ" | awk '{print $1}')"
  if [ -n "$SHA256" ]; then
    echo "$SHA256  $TGZ" | sha256sum -c -
  elif [ "$EXPECTED_TGZ_SHA256" != "__SLS_MASS_NOTIFY_003_SHA256__" ] && [ "$(basename "$TGZ")" = "slsmassnotifyserver-0.0.3-beta.tgz" ] && [ "$actual_sha" != "$EXPECTED_TGZ_SHA256" ]; then
    log "$TGZ does not match the current slsmassnotifyserver-0.0.3-beta package."
    log "Expected SHA256: $EXPECTED_TGZ_SHA256"
    log "Actual SHA256:   $actual_sha"
    log "Remove the stale local TGZ or install with SLS_MASS_NOTIFY_TGZ_URL so the current release is downloaded."
    exit 1
  else
    printf '%s  %s\n' "$actual_sha" "$TGZ"
  fi
  tar -tzf "$TGZ" >/dev/null
}

module_known() {
  fwconsole ma list 2>/dev/null | grep -Eq "\\|[[:space:]]*$1[[:space:]]*\\|"
}

remove_existing_module_registration() {
  if module_known "$MODULE"; then
    fwconsole ma uninstall "$MODULE" >>"$LOG_FILE" 2>&1 || true
    fwconsole ma delete "$MODULE" >>"$LOG_FILE" 2>&1 || true
  fi
  rm -rf "/var/www/html/admin/modules/$MODULE"
}

refresh_module_install() {
  log "Refreshing SLS Mass Notify runtime integration."
  php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >>"$LOG_FILE" 2>&1 || {
    log "Direct runtime refresh reported an error. Continuing if fwconsole installed the runtime successfully. See $LOG_FILE."
  }
}

ensure_runtime_installed() {
  refresh_module_install

  if [ -x /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh ]; then
    return 0
  fi

  log "Runtime installer is missing after fwconsole install and direct refresh. See $LOG_FILE."
  exit 1
}

ensure_piper_runtime() {
  PIPER_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper"
  PIPER_BIN="$PIPER_DIR/venv/bin/piper"
  PIPER_PY="$PIPER_DIR/venv/bin/python"
  mkdir -p "$PIPER_DIR"

  if [ -x "$PIPER_BIN" ] || { [ -x "$PIPER_PY" ] && "$PIPER_PY" -m piper -h >/dev/null 2>&1; }; then
    return 0
  fi

  rm -rf "$PIPER_DIR/venv"
  python3 -m venv "$PIPER_DIR/venv" >>"$LOG_FILE" 2>&1 || {
    log "Unable to create Piper virtualenv. See $LOG_FILE."
    exit 1
  }
  "$PIPER_DIR/venv/bin/pip" install --upgrade pip piper-tts >>"$LOG_FILE" 2>&1 || {
    log "Unable to install piper-tts into the Piper virtualenv. See $LOG_FILE."
    exit 1
  }
  [ -e "$PIPER_BIN" ] && chmod 0755 "$PIPER_BIN" 2>/dev/null || true
  [ -e "$PIPER_PY" ] && chmod 0755 "$PIPER_PY" 2>/dev/null || true
  [ -e "$PIPER_DIR/venv/bin/python3" ] && chmod 0755 "$PIPER_DIR/venv/bin/python3" 2>/dev/null || true

  if [ -x "$PIPER_BIN" ] || { [ -x "$PIPER_PY" ] && "$PIPER_PY" -m piper -h >/dev/null 2>&1; }; then
    return 0
  fi

  log "Piper runtime install completed but no usable Piper entry point was found. See $LOG_FILE."
  exit 1
}

repair_runtime_permissions() {
  PIPER_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper"
  PIPER_BIN="$PIPER_DIR/venv/bin/piper"
  PIPER_PY="$PIPER_DIR/venv/bin/python"
  [ -e "$PIPER_BIN" ] && chmod 0755 "$PIPER_BIN" 2>/dev/null || true
  [ -e "$PIPER_PY" ] && chmod 0755 "$PIPER_PY" 2>/dev/null || true
  [ -e "$PIPER_DIR/venv/bin/python3" ] && chmod 0755 "$PIPER_DIR/venv/bin/python3" 2>/dev/null || true
  if [ -e "$PIPER_BIN" ] || [ -e "$PIPER_PY" ]; then
    rm -f /usr/local/bin/piper
    cat > /usr/local/bin/piper <<'EOF'
#!/bin/sh
PIPER_BIN="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper"
PIPER_PY="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/python"
[ -e "$PIPER_BIN" ] && chmod 0755 "$PIPER_BIN" 2>/dev/null || true
[ -e "$PIPER_PY" ] && chmod 0755 "$PIPER_PY" 2>/dev/null || true
if [ -x "$PIPER_BIN" ]; then
  exec "$PIPER_BIN" "$@"
fi
if [ -x "$PIPER_PY" ] && [ -r "$PIPER_BIN" ]; then
  exec "$PIPER_PY" "$PIPER_BIN" "$@"
fi
if [ -x "$PIPER_PY" ]; then
  exec "$PIPER_PY" -m piper "$@"
fi
echo "Piper TTS binary is not installed or not executable: $PIPER_BIN" >&2
exit 126
EOF
  fi
  [ -e /usr/local/bin/piper ] && chmod 0755 /usr/local/bin/piper 2>/dev/null || true

  mkdir -p /var/www/html/api/sipnotify /var/www/html/api/sls-mass-notify
  cat > /var/www/html/api/sipnotify/.htaccess <<'EOF'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
</IfModule>
EOF
  cat > /var/www/html/api/sls-mass-notify/.htaccess <<'EOF'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
</IfModule>
EOF
  chown -R asterisk:asterisk /var/www/html/api/sipnotify /var/www/html/api/sls-mass-notify 2>/dev/null || true
}

sign_and_verify_touched_modules() {
  signer="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
  if [ ! -x "$signer" ]; then
    log "Local signer is missing or not executable at $signer."
    exit 1
  fi

  modules_to_sign=("$MODULE")
  [ -d /var/www/html/admin/modules/dashboard ] && modules_to_sign+=("dashboard")

  for module_name in "${modules_to_sign[@]}"; do
    "$signer" "$module_name" >>"$LOG_FILE" 2>&1 || {
      log "Unable to locally sign $module_name. See $LOG_FILE."
      tail -60 "$LOG_FILE" 2>/dev/null || true
      exit 1
    }

    SLS_MASS_NOTIFY_MODULE="$module_name" php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE");
$result = \FreePBX::GPG()->verifyModule($module);
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
$status = (int)($result["status"] ?? 0);
if (($status & 129) !== 129) {
    exit(1);
}
exit(0);
' >>"$LOG_FILE" 2>&1 || {
      log "FreePBX signature verification did not return trusted/good for $module_name. See $LOG_FILE."
      tail -60 "$LOG_FILE" 2>/dev/null || true
      exit 1
    }
  done
}

verify_install() {
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole ma list | egrep -i 'slsmassnotifyserver|dashboard|framework|Module'
  asterisk -rx "dialplan show 1000@sls-alert-audio"
  if [ ! -x /usr/local/bin/piper ]; then
    log "Piper wrapper was not created at /usr/local/bin/piper."
    exit 1
  fi
  /usr/local/bin/piper -h >/dev/null
  if [ -x /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper ]; then
    /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper -h >/dev/null
  elif [ -x /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/python ]; then
    /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/python -m piper -h >/dev/null
  else
    log "Piper venv runtime is missing or not executable."
    exit 1
  fi
  python3 -m py_compile /usr/local/bin/sls_mass_notify/sls_notify.py
  php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php >/dev/null
  code="$(curl -k -s -o /tmp/sls-sipnotify-api.out -w '%{http_code}' http://127.0.0.1/api/sipnotify/yealink || true)"
  if [ "$code" != "401" ]; then
    log "SIP Notify API smoke test expected HTTP 401 for /api/sipnotify/yealink, got $code. See /tmp/sls-sipnotify-api.out."
    exit 1
  fi
  ls -lh /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices
}

main() {
  require_freepbx
  cd /tmp
  : >"$LOG_FILE"
  install_dependencies
  preflight_python
  download_tgz
  verify_tgz
  remove_existing_module_registration
  tar -xzf "$TGZ" -C /var/www/html/admin/modules/
  fwconsole ma install "$MODULE" >>"$LOG_FILE" 2>&1 || {
    log "fwconsole install reported an error; continuing to runtime refresh and signing. See $LOG_FILE."
  }
  fwconsole ma enable "$MODULE" >>"$LOG_FILE" 2>&1 || true
  ensure_runtime_installed
  /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh || {
    log "Packaged Piper installer reported an error; attempting direct Piper runtime repair. See $LOG_FILE."
  }
  ensure_piper_runtime
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole chown
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole reload
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole reload
  repair_runtime_permissions
  sign_and_verify_touched_modules
  asterisk -rx "dialplan reload" || true
  verify_install
  log "SLS Mass Notify install finished."
}

main "$@"
