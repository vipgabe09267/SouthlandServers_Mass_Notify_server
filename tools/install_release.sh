#!/usr/bin/env bash
set -euo pipefail

umask 027

MODULE="${SLS_MASS_NOTIFY_MODULE:-slsmassnotifyserver}"
TGZ="${SLS_MASS_NOTIFY_TGZ:-/tmp/slsmassnotifyserver-0.0.7-beta.tgz}"
URL="${SLS_MASS_NOTIFY_TGZ_URL:-${1:-}}"
SHA256="${SLS_MASS_NOTIFY_SHA256:-}"
TOKEN="${SLS_MASS_NOTIFY_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
LOG_FILE="${SLS_MASS_NOTIFY_INSTALL_LOG:-/tmp/slsmassnotifyserver-install.log}"
EXPECTED_TGZ_SHA256="8ee098f8bd7428f437c4dc334dbd96480c3cead8d81b7feeec707ec33742d506"
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
CONFIG_FILE="$DATA_DIR/mass-notifications.config"
CONFIG_SNAPSHOT=""
CONFIG_HASH_BEFORE=""

log() {
  printf '%s\n' "$*"
}

guard_config_on_exit() {
  status=$?
  trap - EXIT

  if [ -n "$CONFIG_SNAPSHOT" ] && [ -f "$CONFIG_SNAPSHOT" ]; then
    current_hash="$(sha256sum "$CONFIG_FILE" 2>/dev/null | awk '{print $1}')"
    if [ "$current_hash" != "$CONFIG_HASH_BEFORE" ]; then
      cp -p "$CONFIG_SNAPSHOT" "$CONFIG_FILE"
      chown asterisk:asterisk "$CONFIG_FILE" 2>/dev/null || true
      chmod 0640 "$CONFIG_FILE" 2>/dev/null || true
      refresh_module_install >/dev/null 2>&1 || true
      log "The installer restored the original central config after an interrupted or failed install."
      status=1
    fi
    rm -f "$CONFIG_SNAPSHOT"
    CONFIG_SNAPSHOT=""
  fi

  exit "$status"
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
    DEBIAN_FRONTEND=noninteractive apt-get install -y curl wget ca-certificates gnupg python3 python3-venv python3-pip sox imagemagick fonts-dejavu-core tar
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
    command -v convert >/dev/null || {
      log "ImageMagick is required for phone alert images."
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
  chmod 0600 "$TGZ"
}

verify_tgz() {
  actual_sha="$(sha256sum "$TGZ" | awk '{print $1}')"
  if [ -n "$SHA256" ]; then
    echo "$SHA256  $TGZ" | sha256sum -c -
  elif [ "$EXPECTED_TGZ_SHA256" != "__SLS_MASS_NOTIFY_007_SHA256__" ] && [ "$(basename "$TGZ")" = "slsmassnotifyserver-0.0.7-beta.tgz" ] && [ "$actual_sha" != "$EXPECTED_TGZ_SHA256" ]; then
    log "$TGZ does not match the current slsmassnotifyserver-0.0.7-beta package."
    log "Expected SHA256: $EXPECTED_TGZ_SHA256"
    log "Actual SHA256:   $actual_sha"
    log "Remove the stale local TGZ or install with SLS_MASS_NOTIFY_TGZ_URL so the current release is downloaded."
    exit 1
  else
    printf '%s  %s\n' "$actual_sha" "$TGZ"
  fi
  TGZ_PATH="$TGZ" MODULE_NAME="$MODULE" python3 - <<'PY'
import os
import pathlib
import tarfile
import xml.etree.ElementTree as ET

archive = os.environ["TGZ_PATH"]
module = os.environ["MODULE_NAME"]
total = 0
seen_module_xml = False
with tarfile.open(archive, "r:gz") as handle:
    members = handle.getmembers()
    if not members or len(members) > 2000:
        raise SystemExit("TGZ has an invalid file count")
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or ".." in path.parts or not path.parts or path.parts[0] != module:
            raise SystemExit(f"Unsafe or unexpected TGZ path: {member.name}")
        if len(member.name) > 240 or member.mode & 0o6000:
            raise SystemExit(f"Unsafe TGZ metadata: {member.name}")
        if member.issym() or member.islnk() or member.isdev() or member.isfifo():
            raise SystemExit(f"Unsupported TGZ member type: {member.name}")
        if member.isfile():
            total += member.size
            if member.name == f"{module}/module.xml":
                seen_module_xml = True
    if total > 50 * 1024 * 1024:
        raise SystemExit("TGZ expands beyond the 50 MB module limit")
    if not seen_module_xml:
        raise SystemExit("TGZ does not contain the required module.xml")
    module_xml = handle.extractfile(f"{module}/module.xml")
    if module_xml is None:
        raise SystemExit("Unable to read module.xml")
    root = ET.fromstring(module_xml.read())
    if (root.findtext("rawname") or "").strip() != module:
        raise SystemExit("module.xml rawname does not match the requested module")
    if (root.findtext("version") or "").strip() != "0.0.7-beta":
        raise SystemExit("module.xml does not contain the expected 0.0.7-beta version")
PY
}

snapshot_config() {
  if [ -L "$CONFIG_FILE" ]; then
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  fi
  [ -r "$CONFIG_FILE" ] || return 0
  CONFIG_SNAPSHOT="$(mktemp /tmp/slsmassnotifyserver-config.XXXXXX)"
  cp -p "$CONFIG_FILE" "$CONFIG_SNAPSHOT"
  CONFIG_HASH_BEFORE="$(sha256sum "$CONFIG_FILE" | awk '{print $1}')"
}

describe_config_drift() {
  before_path="$1"
  after_path="$2"
  CONFIG_BEFORE="$before_path" CONFIG_AFTER="$after_path" python3 - <<'PY' 2>/dev/null || true
import json
import os

with open(os.environ["CONFIG_BEFORE"], encoding="utf-8") as handle:
    before = json.load(handle)
with open(os.environ["CONFIG_AFTER"], encoding="utf-8") as handle:
    after = json.load(handle)

changes = []
def compare(left, right, path="settings"):
    if isinstance(left, dict) and isinstance(right, dict):
        for key in sorted(set(left) | set(right)):
            child = f"{path}.{key}"
            if key not in left:
                changes.append(f"added {child}")
            elif key not in right:
                changes.append(f"removed {child}")
            else:
                compare(left[key], right[key], child)
        return
    if isinstance(left, list) and isinstance(right, list):
        if len(left) != len(right):
            changes.append(f"changed {path} length")
            return
        for index, (left_item, right_item) in enumerate(zip(left, right)):
            compare(left_item, right_item, f"{path}[{index}]")
        return
    if left != right:
        changes.append(f"changed {path}")

compare(before, after)
for item in changes[:25]:
    print(f"Config drift: {item}")
if len(changes) > 25:
    print(f"Config drift: {len(changes) - 25} additional path(s)")
if not changes:
    print("Config drift: JSON values are equivalent; formatting or key order changed")
PY
}

verify_config_unchanged() {
  [ -n "$CONFIG_HASH_BEFORE" ] || return 0
  current_hash="$(sha256sum "$CONFIG_FILE" 2>/dev/null | awk '{print $1}')"
  if [ "$current_hash" = "$CONFIG_HASH_BEFORE" ]; then
    rm -f "$CONFIG_SNAPSHOT"
    CONFIG_SNAPSHOT=""
    return 0
  fi
  describe_config_drift "$CONFIG_SNAPSHOT" "$CONFIG_FILE"
  cp -p "$CONFIG_SNAPSHOT" "$CONFIG_FILE"
  chown asterisk:asterisk "$CONFIG_FILE" 2>/dev/null || true
  chmod 0640 "$CONFIG_FILE" 2>/dev/null || true
  rm -f "$CONFIG_SNAPSHOT"
  CONFIG_SNAPSHOT=""
  refresh_module_install || true
  log "The installer detected an unexpected central config change, restored the original config, and stopped."
  exit 1
}

module_known() {
  fwconsole ma list 2>/dev/null | grep -Eq "\\|[[:space:]]*$1[[:space:]]*\\|"
}

prepare_module_directory() {
  if module_known "$MODULE"; then
    log "Existing SLS Mass Notify module detected; preserving config and overlaying module files."
  fi
  rm -rf "/var/www/html/admin/modules/$MODULE"
}

sync_module_version() {
  php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE") ?: "slsmassnotifyserver";
$xmlPath = "/var/www/html/admin/modules/" . $module . "/module.xml";
if (!is_readable($xmlPath)) {
    exit(0);
}
$xml = simplexml_load_file($xmlPath);
$version = $xml && isset($xml->version) ? trim((string)$xml->version) : "";
if ($version === "") {
    exit(0);
}
$db = \FreePBX::Database();
$stmt = $db->prepare("UPDATE modules SET version = ? WHERE modulename = ?");
$stmt->execute([$version, $module]);
exit(0);
' >>"$LOG_FILE" 2>&1 || true
}

refresh_module_install() {
  log "Refreshing SLS Mass Notify runtime integration."
  if ! php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >>"$LOG_FILE" 2>&1; then
    log "Direct runtime refresh failed. See $LOG_FILE."
    return 1
  fi
}

ensure_runtime_installed() {
  refresh_module_install || {
    tail -60 "$LOG_FILE" 2>/dev/null || true
    exit 1
  }

  if [ -x /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh ]; then
    return 0
  fi

  log "Runtime installer is missing after fwconsole install and direct refresh. See $LOG_FILE."
  exit 1
}

ensure_piper_runtime() {
  PIPER_DIR="/usr/local/bin/sls_mass_notify/piper"
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
  "$PIPER_DIR/venv/bin/pip" install --upgrade 'pip==26.1.2' 'setuptools==83.0.0' 'wheel==0.47.0' >>"$LOG_FILE" 2>&1 || {
    log "Unable to install the pinned Piper packaging runtime. See $LOG_FILE."
    exit 1
  }
  "$PIPER_DIR/venv/bin/pip" install 'piper-tts==1.4.2' >>"$LOG_FILE" 2>&1 || {
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
  PIPER_DIR="/usr/local/bin/sls_mass_notify/piper"
  PIPER_BIN="$PIPER_DIR/venv/bin/piper"
  PIPER_PY="$PIPER_DIR/venv/bin/python"
  [ -e "$PIPER_BIN" ] && chmod 0755 "$PIPER_BIN" 2>/dev/null || true
  [ -e "$PIPER_PY" ] && chmod 0755 "$PIPER_PY" 2>/dev/null || true
  [ -e "$PIPER_DIR/venv/bin/python3" ] && chmod 0755 "$PIPER_DIR/venv/bin/python3" 2>/dev/null || true
  if [ -e "$PIPER_BIN" ] || [ -e "$PIPER_PY" ]; then
    rm -f /usr/local/bin/piper
    cat > /usr/local/bin/piper <<'EOF'
#!/bin/sh
PIPER_BIN="/usr/local/bin/sls_mass_notify/piper/venv/bin/piper"
PIPER_PY="/usr/local/bin/sls_mass_notify/piper/venv/bin/python"
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
  if [ -x /usr/local/bin/sls_mass_notify/piper/venv/bin/piper ]; then
    mkdir -p "$DATA_DIR/piper"
    rm -rf "$DATA_DIR/piper/venv"
    mkdir -p "$DATA_DIR/piper/venv/bin"
    ln -s /usr/local/bin/piper "$DATA_DIR/piper/venv/bin/piper"
  fi

  if [ -d /usr/local/bin/sls_mass_notify ]; then
    chown -R root:root /usr/local/bin/sls_mass_notify
    find /usr/local/bin/sls_mass_notify -type d -exec chmod 0755 {} +
    find /usr/local/bin/sls_mass_notify -type f -exec chmod 0644 {} +
    find /usr/local/bin/sls_mass_notify/piper/venv/bin -type f -exec chmod 0755 {} + 2>/dev/null || true
    chmod 0755 \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh \
      /usr/local/bin/sls_mass_notify/sls_mass_notify_nws_poll.sh \
      /usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh \
      /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py \
	  /usr/local/bin/sls_mass_notify/sls_branded_email.py \
	  /usr/local/bin/sls_mass_notify/sls_branded_discord.py \
      /usr/local/bin/sls_mass_notify/sls_notify.py \
      /usr/local/bin/sls_mass_notify/sls_config.py 2>/dev/null || true
  fi

  mkdir -p /var/www/html/api/sipnotify /var/www/html/api/sls-mass-notify
  cat > /var/www/html/api/sipnotify/.htaccess <<'EOF'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
</IfModule>
EOF
  cat > /var/www/html/api/sls-mass-notify/.htaccess <<'EOF'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
</IfModule>
EOF
  chown -R asterisk:asterisk /var/www/html/api/sipnotify /var/www/html/api/sls-mass-notify 2>/dev/null || true
}

secure_central_config() {
  if [ -L "$CONFIG_FILE" ] || [ ! -f "$CONFIG_FILE" ]; then
    log "Protected central configuration is missing or is not a safe regular file after installation."
    exit 1
  fi
  chown asterisk:asterisk "$CONFIG_FILE"
  chmod 0640 "$CONFIG_FILE"
  [ "$(stat -c '%a %U:%G' "$CONFIG_FILE")" = "640 asterisk:asterisk" ] || {
    log "Protected central configuration permissions or ownership could not be secured."
    exit 1
  }
}

verify_dashboard_integration() {
  local source_section="/var/www/html/admin/modules/$MODULE/dashboard/sections/SlsMassNotifyAnnouncement.class.php"
  local source_view="/var/www/html/admin/modules/$MODULE/dashboard/views/sections/sls-mass-notify-announcement.php"
  local dashboard_section="/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php"
  local dashboard_view="/var/www/html/admin/modules/dashboard/views/sections/sls-mass-notify-announcement.php"

  cmp -s "$source_section" "$dashboard_section" || {
    log "Dashboard announcement section is missing or does not match the installed module."
    exit 1
  }
  cmp -s "$source_view" "$dashboard_view" || {
    log "Dashboard announcement view is missing or does not match the installed module."
    exit 1
  }

  php -r '
require "/etc/freepbx.conf";
$dashboard = \FreePBX::Dashboard();
$hooks = $dashboard->getConfig("allhooks");
$found = false;
foreach ((array)$hooks as $page) {
    foreach ((array)($page["entries"] ?? []) as $entry) {
        if (($entry["rawname"] ?? "") === "SlsMassNotifyAnnouncement"
            && ($entry["section"] ?? "") === "sls_mass_notify_announcement") {
            $found = true;
            break 2;
        }
    }
}
if (!$found) {
    fwrite(STDERR, "Mass Notify announcement hook is absent from the persisted Dashboard index.\n");
    exit(1);
}
require_once "/var/www/html/admin/modules/dashboard/sections/SlsMassNotifyAnnouncement.class.php";
$section = new \FreePBX\modules\Dashboard\Sections\SlsMassNotifyAnnouncement();
$html = $section->getContent("sls_mass_notify_announcement");
if (strpos($html, "dashboard-sls-mass-notify-announcement") === false
    || strpos($html, "Unable to load Mass Notify") !== false) {
    fwrite(STDERR, "Mass Notify announcement panel did not render successfully.\n");
    exit(1);
}
echo "Mass Notify Dashboard announcement panel verified (" . strlen($html) . " bytes).\n";
exit(0);
' >>"$LOG_FILE" 2>&1 || {
    log "Dashboard announcement hook or panel rendering verification failed. See $LOG_FILE."
    exit 1
  }
}

sign_and_verify_touched_modules() {
  signer="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
  if [ ! -x "$signer" ]; then
    log "Local signer is missing or not executable at $signer."
    exit 1
  fi

  modules_to_sign=("$MODULE")
  [ -d /var/www/html/admin/modules/dashboard ] && modules_to_sign+=("dashboard")
  [ -d /var/www/html/admin/modules/framework ] && modules_to_sign+=("framework")

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
  secure_central_config
  sign_and_verify_touched_modules
  verify_dashboard_integration
  module_list="$(fwconsole ma list)"
  printf '%s\n' "$module_list" | egrep -i 'slsmassnotifyserver|dashboard|framework|Module'
  printf '%s\n' "$module_list" | grep -Eq '\|[[:space:]]*slsmassnotifyserver[[:space:]]*\|[^|]*\|[[:space:]]*Enabled[[:space:]]*\|' || {
    log "The SLS Mass Notify module is not enabled after installation."
    exit 1
  }
  for dialplan_function in PJSIP_HEADER PJSIP_CONTACT PJSIP_DIAL_CONTACTS TOLOWER FILTER; do
    function_info="$(asterisk -rx "core show function $dialplan_function" 2>&1)"
    printf '%s\n' "$function_info" | grep -Fq "Info about Function '$dialplan_function'" || {
      log "Required Asterisk dialplan function is unavailable: $dialplan_function"
      exit 1
    }
  done
  audio_dialplan="$(asterisk -rx "dialplan show 1000@sls-alert-audio" 2>&1)"
  printf '%s\n' "$audio_dialplan"
  printf '%s\n' "$audio_dialplan" | grep -Fq 'b(sls-alert-autoanswer^s^1)A(${SLS_SAFE_SOUND})' || {
    log "The SLS audio paging context is missing its portable auto-answer and audio playback handler."
    exit 1
  }
  if printf '%s\n' "$audio_dialplan" | grep -Eq 'macro-autoanswer|b\(autoanswer\^'; then
    log "The SLS audio paging context still depends on a FreePBX internal auto-answer context."
    exit 1
  fi
  autoanswer_dialplan="$(asterisk -rx "dialplan show s@sls-alert-autoanswer" 2>&1)"
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'PJSIP_HEADER(add,Alert-Info)' || {
    log "The SLS PJSIP auto-answer header context is missing."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'PJSIP_HEADER(add,Call-Info)' || {
    log "The SLS PJSIP Call-Info auto-answer header is missing."
    exit 1
  }
  command -v runuser >/dev/null 2>&1 || {
    log "runuser is required to verify Asterisk call-file spool access."
    exit 1
  }
  for spool_dir in /var/spool/asterisk/tmp /var/spool/asterisk/outgoing; do
    [ -d "$spool_dir" ] || {
      log "Required Asterisk call-file spool directory is missing: $spool_dir"
      exit 1
    }
    runuser -u asterisk -- test -w "$spool_dir" \
      && runuser -u asterisk -- test -x "$spool_dir" || {
      log "The asterisk service account cannot search and write to $spool_dir."
      exit 1
    }
  done
  if [ "$(stat -c '%d' /var/spool/asterisk/tmp)" != "$(stat -c '%d' /var/spool/asterisk/outgoing)" ]; then
    log "Asterisk's temporary and outgoing call-file directories are on different filesystems; atomic paging delivery is unavailable."
    exit 1
  fi
  expected_sound_target="$DATA_DIR/sounds"
  for sound_link in \
    /var/lib/asterisk/sounds/SLS_Mass_Notifications_Plugin \
    /var/lib/asterisk/sounds/en/SLS_Mass_Notifications_Plugin; do
    if [ ! -L "$sound_link" ] || [ "$(readlink -f "$sound_link" 2>/dev/null || true)" != "$expected_sound_target" ]; then
      log "$sound_link does not resolve to the protected SLS sound directory."
      exit 1
    fi
  done
  for sound_file in \
    "$DATA_DIR/sounds/tones/opening_Paging_Tone_Opening.wav" \
    "$DATA_DIR/sounds/tones/closing_Paging_Tone_Closing.wav" \
    "$DATA_DIR/sounds/tones/opening_NWS_alert.wav" \
    "$DATA_DIR/sounds/tones/opening_Lightning_alert.wav"; do
    [ -r "$sound_file" ] || {
      log "Required paging sound is missing or unreadable: $sound_file"
      exit 1
    }
    sound_rate="$(soxi -r "$sound_file" 2>/dev/null || true)"
    sound_channels="$(soxi -c "$sound_file" 2>/dev/null || true)"
    sound_bits="$(soxi -b "$sound_file" 2>/dev/null || true)"
    if [ "$sound_rate" != "8000" ] || [ "$sound_channels" != "1" ] || [ "$sound_bits" != "16" ]; then
      log "Paging sound must be 8 kHz, mono, 16-bit PCM: $sound_file"
      exit 1
    fi
  done
  notify_module_ready=0
  for _attempt in $(seq 1 20); do
    if asterisk -rx "module show like res_pjsip_notify.so" 2>/dev/null | grep -q "res_pjsip_notify.so"; then
      notify_module_ready=1
      break
    fi
    asterisk -rx "module load res_pjsip_notify.so" >>"$LOG_FILE" 2>&1 || true
    sleep 1
  done
  [ "$notify_module_ready" -eq 1 ] || {
    log "Asterisk res_pjsip_notify.so is not loaded; SIP NOTIFY cannot work."
    exit 1
  }
  grep -q "SLS Mass Notifications SIP NOTIFY Templates" /etc/asterisk/sip_notify_custom.conf || {
    log "Managed SIP NOTIFY templates were not installed in /etc/asterisk/sip_notify_custom.conf."
    exit 1
  }
  if [ ! -x /usr/local/bin/piper ]; then
    log "Piper wrapper was not created at /usr/local/bin/piper."
    exit 1
  fi
  /usr/local/bin/piper -h >/dev/null
  if [ -x /usr/local/bin/sls_mass_notify/piper/venv/bin/piper ]; then
    /usr/local/bin/sls_mass_notify/piper/venv/bin/piper -h >/dev/null
  elif [ -x /usr/local/bin/sls_mass_notify/piper/venv/bin/python ]; then
    /usr/local/bin/sls_mass_notify/piper/venv/bin/python -m piper -h >/dev/null
  else
    log "Piper venv runtime is missing or not executable."
    exit 1
  fi
  [ -x "$DATA_DIR/piper/venv/bin/piper" ] || {
    log "Piper compatibility executable is missing at $DATA_DIR/piper/venv/bin/piper."
    exit 1
  }
  python3 -c 'compile(open("/usr/local/bin/sls_mass_notify/sls_notify.py", encoding="utf-8").read(), "/usr/local/bin/sls_mass_notify/sls_notify.py", "exec"); compile(open("/usr/local/bin/sls_mass_notify/sls_config.py", encoding="utf-8").read(), "/usr/local/bin/sls_mass_notify/sls_config.py", "exec")'
  config_dump="$(mktemp /tmp/sls-mass-notify-config-check.XXXXXX)"
  /usr/local/bin/sls_mass_notify/sls_config.py "$CONFIG_FILE" >"$config_dump"
  rm -f "$config_dump"
  [ ! -e "$DATA_DIR/mass-notifications.conf" ] || {
    log "Obsolete executable shell configuration still exists at $DATA_DIR/mass-notifications.conf."
    exit 1
  }
  [ ! -e /usr/local/bin/sls_mass_notify/config.ini ] || {
    log "Obsolete duplicate Python configuration still exists."
    exit 1
  }
  convert -size 480x272 xc:'#991b1b' -font DejaVu-Sans-Bold -fill white -gravity center -pointsize 24 -annotate +0+0 'SLS render test' /tmp/sls-mass-notify-render-test.png
  identify /tmp/sls-mass-notify-render-test.png >/dev/null
  rm -f /tmp/sls-mass-notify-render-test.png
  log "Verifying Asterisk PJSIP contact inventory and SLS AMI authentication."
  if ! asterisk -rx "pjsip show contacts" >/tmp/sls-mass-notify-pjsip-contacts.out 2>&1; then
    log "Asterisk could not return the PJSIP contact inventory. See /tmp/sls-mass-notify-pjsip-contacts.out."
    exit 1
  fi
  if ! /usr/bin/timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --ami-health-json \
    >/tmp/sls-mass-notify-ami-health.json 2>/tmp/sls-mass-notify-ami-health.err; then
    cat /tmp/sls-mass-notify-ami-health.err >>"$LOG_FILE" 2>/dev/null || true
    log "SLS AMI authentication, Ping, or PJSIPShowContacts authorization failed. Confirm the loopback slsmassnotify manager user and protected configuration agree; see $LOG_FILE."
    exit 1
  fi
  if grep -Eq 'Objects found:[[:space:]]*[1-9][0-9]*' /tmp/sls-mass-notify-pjsip-contacts.out; then
    endpoint_inventory="$(mktemp /tmp/sls-mass-notify-endpoints.XXXXXX)"
    endpoint_inventory_err="$(mktemp /tmp/sls-mass-notify-endpoints-error.XXXXXX)"
    if ! /usr/bin/timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --list-endpoints-json \
      >"$endpoint_inventory" 2>"$endpoint_inventory_err"; then
      cat "$endpoint_inventory_err" >>"$LOG_FILE" 2>/dev/null || true
      rm -f "$endpoint_inventory" "$endpoint_inventory_err"
      log "SLS AMI PJSIP contact discovery failed. Confirm the loopback manager user has system-event read permission; see $LOG_FILE."
      exit 1
    fi
    if ! python3 - "$endpoint_inventory" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    inventory = json.load(handle)
if not isinstance(inventory, dict):
    raise SystemExit("endpoint inventory is not a JSON object")
for extension, details in inventory.items():
    if not isinstance(extension, str) or not isinstance(details, dict):
        raise SystemExit("endpoint inventory contains an invalid entry")
PY
    then
      cat "$endpoint_inventory" >>"$LOG_FILE" 2>/dev/null || true
      rm -f "$endpoint_inventory" "$endpoint_inventory_err"
      log "SLS AMI PJSIP contact discovery returned invalid data; see $LOG_FILE."
      exit 1
    fi
    rm -f "$endpoint_inventory" "$endpoint_inventory_err"
  else
    log "No registered PJSIP contacts are present; AMI PJSIPShowContacts authorization was verified without waiting for an empty asynchronous inventory."
  fi
  php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php >/dev/null
  code="$(curl -k -s -o /tmp/sls-sipnotify-api.out -w '%{http_code}' http://127.0.0.1/api/sipnotify/desktop || true)"
  if [ "$code" != "401" ]; then
    log "Desktop notification API smoke test expected HTTP 401 for /api/sipnotify/desktop, got $code. See /tmp/sls-sipnotify-api.out."
    exit 1
  fi
  # Use the canonical directory URL. Apache otherwise returns its expected
  # DirectorySlash 301 before the protected API front controller runs.
  control_code="$(curl -k -s -o /tmp/sls-control-api.out -w '%{http_code}' http://127.0.0.1/api/sls-mass-notify/ || true)"
  case "$control_code" in
    401|403|405) ;;
    *)
      log "Control API route smoke test expected HTTP 401/403/405 for /api/sls-mass-notify/, got $control_code. See /tmp/sls-control-api.out."
      exit 1
      ;;
  esac
  [ "$(stat -c '%U:%G' /usr/local/bin/sls_mass_notify)" = "root:root" ] || {
    log "Executable runtime is not owned by root:root."
    exit 1
  }
  [ "$(stat -c '%U:%G' /usr/local/bin/sls_mass_notify/piper/venv/bin/piper)" = "root:root" ] || {
    log "Piper executable is not owned by root:root."
    exit 1
  }
  crontab -l 2>/dev/null | grep -q '/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh' || {
    log "Root automatic-update cron entry was not installed."
    exit 1
  }
  crontab -l 2>/dev/null | grep -q '/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh' || {
    log "Root maintenance cron entry was not installed."
    exit 1
  }
  crontab -u asterisk -l 2>/dev/null | grep -q '/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh' || {
    log "Asterisk weather scheduler cron entry was not installed."
    exit 1
  }
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
  snapshot_config
  trap guard_config_on_exit EXIT
  prepare_module_directory
  tar -xzf "$TGZ" -C /var/www/html/admin/modules/
  fwconsole ma install "$MODULE" >>"$LOG_FILE" 2>&1 || {
    log "fwconsole install reported an error; continuing to runtime refresh and signing. See $LOG_FILE."
  }
  fwconsole ma enable "$MODULE" >>"$LOG_FILE" 2>&1 || true
  SLS_MASS_NOTIFY_MODULE="$MODULE" sync_module_version
  ensure_runtime_installed
  asterisk -rx "module reload res_pjsip_notify.so" >>"$LOG_FILE" 2>&1 || true
  /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh || {
    log "Packaged Piper installer reported an error; attempting direct Piper runtime repair. See $LOG_FILE."
  }
  ensure_piper_runtime
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole chown
  secure_central_config
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole reload
  asterisk -rx "module reload res_pjsip_notify.so" >>"$LOG_FILE" 2>&1 || true
  repair_runtime_permissions
  sign_and_verify_touched_modules
  fwconsole reload
  repair_runtime_permissions
  sign_and_verify_touched_modules
  asterisk -rx "dialplan reload" || true
  verify_install
  verify_config_unchanged
  log "SLS Mass Notify install finished."
}

main "$@"
