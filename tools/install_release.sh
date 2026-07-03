#!/usr/bin/env bash
set -euo pipefail

MODULE="${SLS_MASS_NOTIFY_MODULE:-slsmassnotifyserver}"
TGZ="${SLS_MASS_NOTIFY_TGZ:-/tmp/slsmassnotifyserver-0.0.1-beta.tgz}"
URL="${SLS_MASS_NOTIFY_TGZ_URL:-${1:-}}"
SHA256="${SLS_MASS_NOTIFY_SHA256:-}"
TOKEN="${SLS_MASS_NOTIFY_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
LOG_FILE="${SLS_MASS_NOTIFY_INSTALL_LOG:-/tmp/slsmassnotifyserver-install.log}"

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
  if [ -n "$SHA256" ]; then
    echo "$SHA256  $TGZ" | sha256sum -c -
  else
    sha256sum "$TGZ"
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
  php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install();' >>"$LOG_FILE" 2>&1 || {
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

verify_install() {
  fwconsole ma list | egrep -i 'slsmassnotifyserver|dashboard|framework|Module'
  asterisk -rx "dialplan show 1000@sls-alert-audio"
  piper -h >/dev/null
  /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper -h >/dev/null
  ls -lh /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices
}

main() {
  require_freepbx
  cd /tmp
  : >"$LOG_FILE"
  install_dependencies
  download_tgz
  verify_tgz
  remove_existing_module_registration
  tar -xzf "$TGZ" -C /var/www/html/admin/modules/
  fwconsole ma install "$MODULE"
  fwconsole ma enable "$MODULE" >>"$LOG_FILE" 2>&1 || true
  ensure_runtime_installed
  /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh
  fwconsole chown
  fwconsole reload
  asterisk -rx "dialplan reload" || true
  verify_install
  log "SLS Mass Notify install finished."
}

main "$@"
