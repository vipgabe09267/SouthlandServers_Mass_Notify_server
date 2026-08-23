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
TGZ="${SLS_MASS_NOTIFY_TGZ:-/tmp/slsmassnotifyserver-0.1.0.tgz}"
URL="${SLS_MASS_NOTIFY_TGZ_URL:-${1:-}}"
SHA256="${SLS_MASS_NOTIFY_SHA256:-}"
TOKEN="${SLS_MASS_NOTIFY_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
LOG_FILE="${SLS_MASS_NOTIFY_INSTALL_LOG:-/tmp/slsmassnotifyserver-install.log}"
EXPECTED_TGZ_SHA256="90ae525738d117a8141722590b33dbd4f4b23c32f31cb6e761f329e98504d704"
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
CONFIG_FILE="$DATA_DIR/mass-notifications.config"
CONFIG_SNAPSHOT=""
CONFIG_HASH_BEFORE=""
CONFIG_LOCK_FD=""
SETTINGS_LOCK="$DATA_DIR/mass-notifications.config.lock"
STAGING_DIR=""
MODULE_BACKUP_DIR=""
MODULE_ACTIVATED=0
INSTALL_COMMITTED=0
HAD_EXISTING_MODULE=0
MODULE_WAS_ENABLED=0
APT_METADATA_REFRESHED=0
ASTERISK_PACKAGE_REPAIR_ATTEMPTS=""
REPAIR_ASTERISK_PACKAGES="${SLS_MASS_NOTIFY_REPAIR_ASTERISK_PACKAGES:-1}"
INSTALL_MAINTENANCE_LOCK_FD=""
FREEPBX_CONFIRMED=0
# Regression tests source this installer so they can exercise its protected
# filesystem helpers.  Keep their fixture failure markers from creating or
# deleting real FreePBX Dashboard notifications on the host running the test.
INSTALL_NOTIFICATION_SIDE_EFFECTS=1
INSTALL_STAGE="initialization"
INSTALL_SOLUTION="Review /tmp/slsmassnotifyserver-install.log, correct the reported prerequisite, then run the same installer again."
INSTALL_FAILURE_FILE="$DATA_DIR/install-failure.json"

log() {
  printf '%s\n' "$*"
}

set_install_stage() {
  INSTALL_STAGE="$1"
  INSTALL_SOLUTION="$2"
}

ensure_data_directory() {
  DATA_DIRECTORY_PATH="$DATA_DIR" /usr/bin/python3 - <<'PY'
import os
import pwd
import stat

path = os.environ["DATA_DIRECTORY_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path:
    raise SystemExit(2)
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    try:
        directory_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    except FileNotFoundError:
        os.mkdir(parts[-1], 0o750, dir_fd=parent_fd)
        directory_fd = os.open(parts[-1], flags, dir_fd=parent_fd)
    try:
        if not stat.S_ISDIR(os.fstat(directory_fd).st_mode):
            raise SystemExit(3)
        account = pwd.getpwnam("asterisk")
        os.fchmod(directory_fd, 0o750)
        os.fchown(directory_fd, account.pw_uid, account.pw_gid)
    finally:
        os.close(directory_fd)
finally:
    os.close(parent_fd)
PY
}

record_install_failure() {
  local marker_tmp
  [ "$FREEPBX_CONFIRMED" -eq 1 ] || return 0
  ensure_data_directory 2>/dev/null || return 0
  marker_tmp="$(mktemp /tmp/slsmassnotifyserver-install-failure.XXXXXX 2>/dev/null || true)"
  [ -n "$marker_tmp" ] || return 0
  if ! /usr/bin/php -r '
$payload = [
    "version" => 1,
    "failed_at" => gmdate("c"),
    "stage" => substr(preg_replace("/[^A-Za-z0-9 ._\/-]/", "", (string)$argv[1]), 0, 80),
    "message" => "SLS Mass Notify installation did not complete.",
    "solution" => substr(preg_replace("/[[:cntrl:]]/", " ", (string)$argv[2]), 0, 400),
    "log" => "/tmp/slsmassnotifyserver-install.log",
];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($argv[3], $json . PHP_EOL, LOCK_EX) === false) {
    exit(1);
}
' "$INSTALL_STAGE" "$INSTALL_SOLUTION" "$marker_tmp" 2>/dev/null; then
    rm -f "$marker_tmp"
    return 0
  fi
  safe_config_restore "$marker_tmp" "$INSTALL_FAILURE_FILE" 2>/dev/null || {
    rm -f "$marker_tmp"
    return 0
  }
  rm -f "$marker_tmp"
  [ "${INSTALL_NOTIFICATION_SIDE_EFFECTS:-1}" -eq 1 ] || return 0
  /usr/bin/php -r '
require "/etc/freepbx.conf";
$detail = "Stage: " . $argv[1] . ". " . $argv[2] . " Installer log: /tmp/slsmassnotifyserver-install.log";
\FreePBX::Notifications()->add_error(
    "slsmassnotifyserver",
    "INSTALLFAILED",
    "SLS Mass Notify installation failed",
    $detail,
    "",
    true,
    true
);
exit(0);
' "$INSTALL_STAGE" "$INSTALL_SOLUTION" >/dev/null 2>&1 || true
}

clear_install_failure() {
  if [ ! -L "$INSTALL_FAILURE_FILE" ]; then
    rm -f "$INSTALL_FAILURE_FILE" 2>/dev/null || true
  fi
  [ "${INSTALL_NOTIFICATION_SIDE_EFFECTS:-1}" -eq 1 ] || return 0
  /usr/bin/php -r '
require "/etc/freepbx.conf";
\FreePBX::Notifications()->delete("slsmassnotifyserver", "INSTALLFAILED");
exit(0);
' >/dev/null 2>&1 || true
}

local_web_probe() {
  local path="$1"
  local output="$2"
  local expected_pattern="$3"
  local code host port scheme
  local -a curl_args
  curl_args=(-ksS --noproxy '*' --connect-timeout 5 --max-time 20 -o "$output" -w '%{http_code}')

  for scheme in http https; do
    code="$(curl "${curl_args[@]}" "${scheme}://127.0.0.1${path}" || true)"
    if [[ "$code" =~ $expected_pattern ]]; then
      printf '%s\n' "$code"
      return 0
    fi
  done

  host="$(php -r '
$settings = json_decode((string)@file_get_contents(
    "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config"
), true);
$host = strtolower(trim((string)($settings["public_pbx_host"] ?? "")));
if (preg_match("/^[a-z0-9.-]+$/", $host)) {
    echo $host;
}
' 2>/dev/null || true)"
  if [ -n "$host" ] && [ "$host" != "127.0.0.1" ] && [ "$host" != "localhost" ]; then
    for scheme in http https; do
      if [ "$scheme" = "https" ]; then
        port=443
      else
        port=80
      fi
      code="$(curl "${curl_args[@]}" --resolve "${host}:${port}:127.0.0.1" \
        "${scheme}://${host}${path}" || true)"
      if [[ "$code" =~ $expected_pattern ]]; then
        printf '%s\n' "$code"
        return 0
      fi
    done
  fi

  printf '%s\n' "${code:-000}"
  return 1
}

guard_config_on_exit() {
  status=$?
  trap - EXIT

  if [ "$status" -ne 0 ] && [ "$MODULE_ACTIVATED" -eq 1 ] && [ "$INSTALL_COMMITTED" -eq 0 ]; then
    log "Installation did not complete; removing partial integration and restoring the previous module files."
    # The activated module must remain present while its uninstall hook removes
    # AMI, dialplan, Apache, cron, Dashboard, API, and runtime integration.
    preserve_piper=0
    [ "$HAD_EXISTING_MODULE" -eq 1 ] && preserve_piper=1
    if ! SLS_MASS_NOTIFY_PRESERVE_PIPER_RUNTIME="$preserve_piper" fwconsole ma uninstall "$MODULE" >/dev/null 2>&1; then
      export SLS_MASS_NOTIFY_PRESERVE_PIPER_RUNTIME="$preserve_piper"
      php -r '
require "/etc/freepbx.conf";
require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php";
$class = "\\FreePBX\\modules\\Slsmassnotifyserver";
$obj = new $class(\FreePBX::Create());
$obj->uninstall();
exit(0);
' >/dev/null 2>&1 || true
      unset SLS_MASS_NOTIFY_PRESERVE_PIPER_RUNTIME
    fi
    rm -rf "/var/www/html/admin/modules/$MODULE"
    if [ -n "$MODULE_BACKUP_DIR" ] && [ -d "$MODULE_BACKUP_DIR/$MODULE" ]; then
      mv "$MODULE_BACKUP_DIR/$MODULE" "/var/www/html/admin/modules/$MODULE"
      fwconsole ma install "$MODULE" >/dev/null 2>&1 || true
      if [ "$MODULE_WAS_ENABLED" -eq 1 ]; then
        fwconsole ma enable "$MODULE" >/dev/null 2>&1 || true
      else
        fwconsole ma disable "$MODULE" >/dev/null 2>&1 || true
      fi
      SLS_MASS_NOTIFY_MODULE="$MODULE" sync_module_version
      refresh_module_install >/dev/null 2>&1 || true
    else
      fwconsole ma delete "$MODULE" >/dev/null 2>&1 || true
    fi
  fi

  if [ -n "$CONFIG_SNAPSHOT" ] && [ -f "$CONFIG_SNAPSHOT" ]; then
    current_hash="$(safe_config_hash "$CONFIG_FILE" 2>/dev/null || true)"
    if [ "$current_hash" != "$CONFIG_HASH_BEFORE" ]; then
      if ensure_data_directory 2>/dev/null && safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE" 2>/dev/null; then
        refresh_module_install >/dev/null 2>&1 || true
        log "The installer restored the original central config after an interrupted or failed install."
        status=1
      else
        log "CRITICAL: the installer could not safely restore the protected central config. The root-owned snapshot remains at $CONFIG_SNAPSHOT."
        CONFIG_SNAPSHOT=""
        status=1
      fi
    fi
    if [ -n "$CONFIG_SNAPSHOT" ]; then
      rm -f "$CONFIG_SNAPSHOT"
      CONFIG_SNAPSHOT=""
    fi
  fi

  if [ -n "$STAGING_DIR" ] && [ -d "$STAGING_DIR" ]; then
    rm -rf "$STAGING_DIR"
  fi
  if [ -n "$MODULE_BACKUP_DIR" ] && [ -d "$MODULE_BACKUP_DIR" ]; then
    rm -rf "$MODULE_BACKUP_DIR"
  fi

  if [ "$status" -ne 0 ]; then
    record_install_failure
  fi

  exit "$status"
}

require_freepbx() {
  local bootstrap_utility
  [ "${EUID:-$(id -u)}" -eq 0 ] || {
    log "Run the installer as root."
    exit 1
  }
  case "$REPAIR_ASTERISK_PACKAGES" in
    0|1) ;;
    *)
      log "SLS_MASS_NOTIFY_REPAIR_ASTERISK_PACKAGES must be 0 or 1."
      exit 1
      ;;
  esac
  [ -x /usr/sbin/fwconsole ] || {
    log "The required FreePBX CLI is unavailable at /usr/sbin/fwconsole. This runtime cannot activate safely on a nonstandard FreePBX path."
    exit 1
  }
  [ -d /var/www/html/admin/modules ] || {
    log "/var/www/html/admin/modules not found. This does not look like a FreePBX server."
    exit 1
  }
  [ -x /usr/sbin/asterisk ] || {
    log "The required Asterisk CLI is unavailable at /usr/sbin/asterisk. This runtime cannot activate safely on a nonstandard Asterisk path."
    exit 1
  }
  [ -r /etc/freepbx.conf ] || {
    log "/etc/freepbx.conf is missing or unreadable."
    exit 1
  }
  FREEPBX_CONFIRMED=1
  # A minimal FreePBX image may not yet have the locking/process utilities or
  # PHP features needed by the coordination and bootstrap checks below. Repair
  # those only after the target has been identified as a FreePBX host, but
  # before trying to use them.
  install_bootstrap_dependencies
  for bootstrap_utility in /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/sbin/runuser; do
    [ -x "$bootstrap_utility" ] || {
      log "Required installation coordination utility is unavailable: $bootstrap_utility"
      exit 1
    }
  done
  /usr/bin/php -r '
$bootstrap_settings = ["freepbx_auth" => false, "skip_astman" => true];
require "/etc/freepbx.conf";
\FreePBX::Database()->query("SELECT 1");
$managerHost = strtolower(trim((string)\FreePBX::Config()->get("ASTMANAGERHOST")));
$managerPort = (int)\FreePBX::Config()->get("ASTMANAGERPORT");
if ($managerHost === "") {
    $managerHost = "localhost";
}
if (!in_array($managerHost, ["localhost", "127.0.0.1", "::1"], true)) {
    fwrite(STDERR, "SLS Mass Notify requires FreePBX AMI to use a loopback host; configured ASTMANAGERHOST is unsupported.\n");
    exit(1);
}
if ($managerPort < 1 || $managerPort > 65535) {
    fwrite(STDERR, "FreePBX ASTMANAGERPORT is invalid.\n");
    exit(1);
}
exit(0);
' >>"$LOG_FILE" 2>&1 || {
    log "FreePBX bootstrap or database access failed. See $LOG_FILE."
    exit 1
  }
  fwconsole --version 2>/dev/null | grep -Eq '(^|[[:space:]])17\.' || {
    log "This release requires FreePBX 17."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials."
    exit 1
  }
}

acquire_maintenance_coordination() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
  local descriptor descriptor_path descriptor_target

  [[ "$lock_file" = /* ]] && [ "$lock_file" != "/" ] || {
    log "Unsafe maintenance lock path."
    exit 1
  }
  install -d -m 0755 -o root -g root "$(dirname "$lock_file")"
  [ ! -L "$lock_file" ] || {
    log "Refusing a symbolic-link maintenance lock: $lock_file"
    exit 1
  }

  # A UI-triggered update is launched by the maintenance worker while it holds
  # this lock. Reuse that inherited lock instead of deadlocking the child
  # installer; direct CLI installs acquire their own lock and keep the minute
  # maintenance job out of the module/signing transaction.
  for descriptor_path in "/proc/${BASHPID}/fd/"*; do
    [ -e "$descriptor_path" ] || continue
    descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
    [ "$descriptor_target" = "$lock_file" ] || continue
    descriptor="${descriptor_path##*/}"
    if [[ "$descriptor" =~ ^[0-9]+$ ]] && flock -n "$descriptor"; then
      log "Using the maintenance worker's inherited installation lock."
      return 0
    fi
  done

  exec {INSTALL_MAINTENANCE_LOCK_FD}>"$lock_file"
  chown root:root "$lock_file"
  chmod 0600 "$lock_file"
  flock -w 120 "$INSTALL_MAINTENANCE_LOCK_FD" || {
    log "Another Mass Notify maintenance or update operation is still running."
    log "Wait for it to finish, then rerun the installer."
    exit 1
  }
}

ensure_freepbx_prerequisites() {
  local prerequisite module_list
  module_list="$(fwconsole ma list 2>/dev/null)"
  for prerequisite in framework dashboard backup recordings; do
    if ! printf '%s\n' "$module_list" | grep -Eq "\\|[[:space:]]*${prerequisite}[[:space:]]*\\|"; then
      log "Installing required FreePBX module: $prerequisite"
      fwconsole ma --no-interaction --ignorecache downloadinstall "$prerequisite" >>"$LOG_FILE" 2>&1 || {
        log "Required FreePBX module could not be installed: $prerequisite. See $LOG_FILE."
        exit 1
      }
    fi
    module_list="$(fwconsole ma list 2>/dev/null)"
    if ! printf '%s\n' "$module_list" | grep -Eq "\\|[[:space:]]*${prerequisite}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|"; then
      log "Enabling required FreePBX module: $prerequisite"
      fwconsole ma --no-interaction enable "$prerequisite" >>"$LOG_FILE" 2>&1 || {
        log "Required FreePBX module could not be enabled without an administrator-managed upgrade: $prerequisite. See $LOG_FILE."
        exit 1
      }
    fi
  done
}

strip_asterisk_ansi() {
  # Asterisk can emit terminal color/control sequences even when invoked from
  # a noninteractive installer. Remove CSI sequences before parsing headings.
  LC_ALL=C sed -E $'s/\033\\[[0-?]*[ -\\/]*[@-~]//g'
}

asterisk_cli_output() {
  local command="$1"
  local output

  output="$(asterisk -rx "$command" 2>&1 || true)"
  strip_asterisk_ansi <<<"$output"
}

asterisk_setting_value() {
  local setting_name="$1"
  local settings

  settings="$(asterisk_cli_output "core show settings")"
  printf '%s\n' "$settings" | awk -v wanted="$setting_name" '
function trim(value) {
  sub(/^[[:space:]]+/, "", value)
  sub(/[[:space:]]+$/, "", value)
  return value
}
{
  separator = index($0, ":")
  if (separator == 0) {
    next
  }
  key = trim(substr($0, 1, separator - 1))
  if (tolower(key) == tolower(wanted)) {
    print trim(substr($0, separator + 1))
    exit
  }
}'
}

asterisk_capability_available() {
  local capability_type="$1"
  local capability_name="$2"
  local capability_info marker

  case "$capability_type" in
    function)
      capability_info="$(asterisk_cli_output "core show function $capability_name")"
      marker="Info about Function '$capability_name'"
      ;;
    application)
      capability_info="$(asterisk_cli_output "core show application $capability_name")"
      marker="Info about Application '$capability_name'"
      ;;
    *)
      log "Unknown Asterisk capability type: $capability_type"
      return 1
      ;;
  esac

  # Asterisk releases and downstream builds vary the heading capitalization
  # ("Function" versus "function"). Match the complete quoted capability
  # heading case-insensitively so a registered provider never falls through to
  # package repair because of presentation-only CLI differences.
  LC_ALL=C grep -Fqi -- "$marker" <<<"$capability_info"
}

asterisk_module_file() {
  local module_name="$1"
  local module_directory

  module_directory="$(asterisk_setting_value "Module directory")"
  if [ -n "$module_directory" ] && [[ "$module_directory" = /* ]]; then
    printf '%s/%s\n' "${module_directory%/}" "$module_name"
  fi
}

refresh_apt_metadata() {
  if [ "$APT_METADATA_REFRESHED" -eq 1 ]; then
    return 0
  fi
  command -v apt-get >/dev/null 2>&1 || return 1
  log "Refreshing Debian package metadata for dependency verification."
  apt-get update >>"$LOG_FILE" 2>&1 || return 1
  APT_METADATA_REFRESHED=1
}

asterisk_provider_package() {
  local provider_file="$1"
  local canonical_provider query record package record_path canonical_record status
  local -a queries

  command -v dpkg-query >/dev/null 2>&1 || return 1
  canonical_provider="$(readlink -m -- "$provider_file" 2>/dev/null || true)"
  [ -n "$canonical_provider" ] || return 1
  queries=("$provider_file")
  if [ "$canonical_provider" != "$provider_file" ]; then
    queries+=("$canonical_provider")
  fi
  queries+=("*/asterisk/modules/$(basename "$provider_file")")

  for query in "${queries[@]}"; do
    while IFS= read -r record; do
      [[ "$record" == *": "* ]] || continue
      package="${record%%: *}"
      record_path="${record#*: }"
      [[ "$package" =~ ^[a-z0-9][a-z0-9+.-]*(:[a-z0-9][a-z0-9-]*)?$ ]] || continue
      canonical_record="$(readlink -m -- "$record_path" 2>/dev/null || true)"
      [ "$canonical_record" = "$canonical_provider" ] || continue
      status="$(dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null || true)"
      [[ "$status" == ii* ]] || continue
      printf '%s\n' "$package"
      return 0
    done < <(dpkg-query -S "$query" 2>/dev/null || true)
  done
  return 1
}

wait_for_asterisk_cli() {
  local attempt=1
  while [ "$attempt" -le 30 ]; do
    if asterisk -rx "core show version" >>"$LOG_FILE" 2>&1; then
      return 0
    fi
    sleep 1
    attempt=$((attempt + 1))
  done
  return 1
}

repair_asterisk_provider_package() {
  local provider_module="$1"
  local provider_file package package_version package_spec utility
  local active_channels

  [ "$REPAIR_ASTERISK_PACKAGES" -eq 1 ] || {
    log "Automatic Asterisk provider-package repair is disabled."
    return 1
  }
  for utility in apt-get dpkg-query readlink; do
    command -v "$utility" >/dev/null 2>&1 || {
      log "Automatic Asterisk provider-package repair requires $utility, but it is unavailable."
      return 1
    }
  done
  if ! asterisk -rx "core show version" >>"$LOG_FILE" 2>&1; then
    log "Asterisk is not reachable; refusing to start or alter its packages automatically."
    return 1
  fi
  active_channels="$(asterisk -rx "core show channels count" 2>/dev/null \
    | awk '$2 == "active" && $3 == "channels" {print $1; exit}')"
  if ! [[ "$active_channels" =~ ^[0-9]+$ ]]; then
    log "Unable to verify that Asterisk has no active channels; automatic package repair was deferred."
    return 1
  fi
  if [ "$active_channels" -gt 0 ]; then
    log "Automatic Asterisk package repair was deferred because $active_channels channel(s) are active."
    log "Rerun the installer after active calls have completed."
    return 1
  fi
  provider_file="$(asterisk_module_file "$provider_module")"
  [ -n "$provider_file" ] || {
    log "Unable to determine the active Asterisk module directory for $provider_module."
    return 1
  }
  package="$(asterisk_provider_package "$provider_file" || true)"
  [ -n "$package" ] || {
    log "No installed Debian package owns $provider_file; this appears to be a custom or incomplete Asterisk build."
    log "The installer will not mix a packaged module into an unowned Asterisk build because that can corrupt the PBX ABI."
    return 1
  }
  package_version="$(dpkg-query -W -f='${Version}' "$package" 2>/dev/null || true)"
  [ -n "$package_version" ] || {
    log "Unable to determine the installed version of the Asterisk provider package $package."
    return 1
  }
  package_spec="${package}=${package_version}"
  if [[ "$ASTERISK_PACKAGE_REPAIR_ATTEMPTS" == *"|${package_spec}|"* ]]; then
    log "The exact Asterisk package $package_spec was already repaired during this installer run."
    return 1
  fi
  ASTERISK_PACKAGE_REPAIR_ATTEMPTS="${ASTERISK_PACKAGE_REPAIR_ATTEMPTS}|${package_spec}|"

  refresh_apt_metadata || {
    log "Unable to refresh Debian package metadata needed to repair $provider_module. See $LOG_FILE."
    return 1
  }

  log "Repairing $provider_module from the exact installed Asterisk package $package_spec."
  DEBIAN_FRONTEND=noninteractive apt-get install -y --reinstall --no-install-recommends --no-remove \
    "$package_spec" >>"$LOG_FILE" 2>&1 || {
      log "Exact-version Asterisk package repair failed for $package_spec. See $LOG_FILE."
      log "Restore that exact package version or its matching repository before rerunning the installer."
      return 1
    }
  [ -f "$provider_file" ] || {
    log "Package repair completed, but $provider_file is still missing."
    return 1
  }
  if ! wait_for_asterisk_cli; then
    log "Asterisk stopped during package repair; attempting a normal FreePBX start."
    fwconsole start >>"$LOG_FILE" 2>&1 || {
      log "FreePBX could not start Asterisk after package repair. See $LOG_FILE."
      return 1
    }
    wait_for_asterisk_cli || {
      log "Asterisk did not return after the exact-version package repair. See $LOG_FILE."
      return 1
    }
  fi
  return 0
}

asterisk_module_loaded() {
  local module_name="$1"
  local module_info line normalized_module_name field_count
  local -a fields
  module_info="$(asterisk_cli_output "module show like $module_name")"
  normalized_module_name="${module_name,,}"
  while IFS= read -r line; do
    read -r -a fields <<<"$line"
    field_count="${#fields[@]}"
    if [ "$field_count" -ge 3 ] \
      && [ "${fields[0],,}" = "$normalized_module_name" ] \
      && [ "${fields[field_count - 2],,}" = "running" ] \
      && { [ "$field_count" -lt 4 ] || [ "${fields[field_count - 3],,}" != "not" ]; }; then
      return 0
    fi
  done <<<"$module_info"
  return 1
}

require_asterisk_capability() {
  local capability_type="$1"
  local capability_name="$2"

  if asterisk_capability_available "$capability_type" "$capability_name"; then
    return 0
  fi
  log "Required built-in Asterisk $capability_type is unavailable: $capability_name. The installer cannot safely repair a built-in capability."
  return 1
}

ensure_asterisk_module_loaded() {
  local module_name="$1"

  if asterisk_module_loaded "$module_name"; then
    return 0
  fi
  log "Loading required Asterisk module $module_name."
  asterisk -rx "module load $module_name" >>"$LOG_FILE" 2>&1 || true
  if ! asterisk_module_loaded "$module_name" \
    && repair_asterisk_provider_package "$module_name"; then
    asterisk -rx "module load $module_name" >>"$LOG_FILE" 2>&1 || true
  fi
  asterisk_module_loaded "$module_name" || {
    log "Required Asterisk module is unavailable after adaptive repair: $module_name. See $LOG_FILE."
    return 1
  }
}

ensure_asterisk_capability() {
  local capability_type="$1"
  local capability_name="$2"
  local provider_module="$3"
  local provider_file

  if asterisk_capability_available "$capability_type" "$capability_name"; then
    return 0
  fi

  log "Asterisk $capability_type $capability_name is not registered; loading $provider_module."
  asterisk -rx "module load $provider_module" >>"$LOG_FILE" 2>&1 || true
  if ! asterisk_capability_available "$capability_type" "$capability_name"; then
    asterisk -rx "module reload $provider_module" >>"$LOG_FILE" 2>&1 || true
  fi
  if ! asterisk_capability_available "$capability_type" "$capability_name" \
    && repair_asterisk_provider_package "$provider_module"; then
    asterisk -rx "module load $provider_module" >>"$LOG_FILE" 2>&1 || true
    if ! asterisk_capability_available "$capability_type" "$capability_name"; then
      asterisk -rx "module reload $provider_module" >>"$LOG_FILE" 2>&1 || true
    fi
  fi
  if ! asterisk_capability_available "$capability_type" "$capability_name"; then
    provider_file="$(asterisk_module_file "$provider_module")"
    if [ -n "$provider_file" ] && [ ! -f "$provider_file" ]; then
      log "Asterisk $capability_type $capability_name requires $provider_module, but the active Asterisk build does not include $provider_file."
      log "Install the matching Asterisk core/module package for this PBX, then rerun the installer."
    else
      log "Asterisk could not register $capability_type $capability_name from $provider_module. See $LOG_FILE."
    fi
    return 1
  fi

}

ensure_required_asterisk_capabilities() {
  ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so || return 1
  ensure_asterisk_capability function PJSIP_CONTACT func_pjsip_contact.so || return 1
  ensure_asterisk_capability function PJSIP_AOR func_pjsip_aor.so || return 1
  ensure_asterisk_capability function PJSIP_DIAL_CONTACTS chan_pjsip.so || return 1
  ensure_asterisk_capability function TOLOWER func_strings.so || return 1
  ensure_asterisk_capability function CUT func_strings.so || return 1
  ensure_asterisk_capability function FILTER func_strings.so || return 1
  ensure_asterisk_capability function CHANNEL func_channel.so || return 1
  ensure_asterisk_capability function IF func_logic.so || return 1
  ensure_asterisk_capability function DB func_db.so || return 1
  ensure_asterisk_capability function CALLERID func_callerid.so || return 1
  ensure_asterisk_capability application ConfBridge app_confbridge.so || return 1
  ensure_asterisk_capability application Page app_page.so || return 1
  ensure_asterisk_capability application ExecIf app_exec.so || return 1
  ensure_asterisk_capability application Gosub app_stack.so || return 1
  ensure_asterisk_capability application Return app_stack.so || return 1
  ensure_asterisk_capability application Log app_verbose.so || return 1
  ensure_asterisk_capability application Verbose app_verbose.so || return 1
  require_asterisk_capability application Wait || return 1
}

asterisk_module_starts_persistently() {
  local module_name="$1"
  local modules_config="${2:-/etc/asterisk/modules.conf}"
  [ -r "$modules_config" ] || return 1
  /usr/bin/awk -v wanted="${module_name,,}" '
    BEGIN { autoload = 0; explicit = 0; blocked = 0 }
    {
      line = $0
      sub(/[;#].*$/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      split(line, fields, /[[:space:]]*=>?[[:space:]]*/)
      key = tolower(fields[1])
      value = tolower(fields[2])
      if (key == "autoload" && value == "yes") autoload = 1
      if ((key == "load" || key == "preload") && value == wanted) explicit = 1
      if (key == "noload" && value == wanted) blocked = 1
    }
    END { exit(blocked || (!autoload && !explicit) ? 1 : 0) }
  ' "$modules_config"
}

verify_asterisk_module_startup_persistence() {
  local module_name
  for module_name in \
    res_pjsip.so res_pjsip_session.so chan_pjsip.so res_pjsip_notify.so \
    pbx_spool.so format_wav.so res_pjsip_header_funcs.so \
    func_pjsip_contact.so func_pjsip_aor.so func_strings.so func_channel.so \
    func_logic.so func_db.so func_callerid.so app_exec.so \
    app_page.so app_confbridge.so app_stack.so app_verbose.so; do
    asterisk_module_starts_persistently "$module_name" || {
      log "Required Asterisk module is available now but is blocked after restart by /etc/asterisk/modules.conf: $module_name"
      log "Enable Asterisk module autoloading or explicitly load that module through the PBX-managed module configuration, then rerun the installer."
      return 1
    }
  done
}

asterisk_channel_type_available() {
  local channel_type="$1"
  local channel_info

  channel_info="$(asterisk_cli_output "core show channeltype $channel_type")"
  LC_ALL=C grep -Fqi -- "Info about channel driver: $channel_type" <<<"$channel_info"
}

verify_supported_asterisk_directories() {
  local spool_directory varlib_directory data_directory
  local canonical_spool canonical_varlib canonical_data

  spool_directory="$(asterisk_setting_value "Spool directory")"
  varlib_directory="$(asterisk_setting_value "VarLib directory")"
  data_directory="$(asterisk_setting_value "Data directory")"

  if [[ "$spool_directory" != /* ]]; then
    log "Unable to determine Asterisk's active Spool directory from 'core show settings'."
    return 1
  fi
  if [[ "$varlib_directory" != /* ]]; then
    log "Unable to determine Asterisk's active VarLib directory from 'core show settings'."
    return 1
  fi

  canonical_spool="$(readlink -m -- "$spool_directory" 2>/dev/null || true)"
  canonical_varlib="$(readlink -m -- "$varlib_directory" 2>/dev/null || true)"
  if [ "$canonical_spool" != "/var/spool/asterisk" ]; then
    log "Unsupported Asterisk Spool directory: $spool_directory. This release writes call files only to /var/spool/asterisk and will not activate against a different live spool path."
    return 1
  fi
  if [ "$canonical_varlib" != "/var/lib/asterisk" ]; then
    log "Unsupported Asterisk VarLib directory: $varlib_directory. This release stores sounds and state only under /var/lib/asterisk and will not activate against a different live data path."
    return 1
  fi

  # Some builds omit Data directory from the CLI. When it is reported, ensure
  # that the module's fixed sound path points at the active Asterisk data root.
  if [ -n "$data_directory" ]; then
    if [[ "$data_directory" != /* ]]; then
      log "Asterisk reported a non-absolute Data directory: $data_directory."
      return 1
    fi
    canonical_data="$(readlink -m -- "$data_directory" 2>/dev/null || true)"
    if [ "$canonical_data" != "/var/lib/asterisk" ]; then
      log "Unsupported Asterisk Data directory: $data_directory. This release expects /var/lib/asterisk for sounds."
      return 1
    fi
  fi

  return 0
}

asterisk_labeled_value() {
  local command="$1"
  local label="$2"
  local output

  output="$(asterisk_cli_output "$command")"
  printf '%s\n' "$output" | awk -v wanted="$label" '
function trim(value) {
  sub(/^[[:space:]]+/, "", value)
  sub(/[[:space:]]+$/, "", value)
  return value
}
{
  separator = index($0, ":")
  if (separator == 0) {
    next
  }
  key = trim(substr($0, 1, separator - 1))
  if (tolower(key) == tolower(wanted)) {
    print trim(substr($0, separator + 1))
    exit
  }
}'
}

pjsip_dial_string_supported() {
  local dial_string="$1"
  local channel
  local -a channels

  [ -n "$dial_string" ] || return 1
  IFS='&' read -r -a channels <<<"$dial_string"
  [ "${#channels[@]}" -gt 0 ] || return 1
  for channel in "${channels[@]}"; do
    channel="$(printf '%s' "$channel" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    [ -n "$channel" ] || return 1
    [[ "${channel,,}" == pjsip/* ]] || return 1
  done
}

pjsip_endpoint_available() {
  local endpoint="$1"
  local endpoint_info

  [[ "$endpoint" =~ ^[A-Za-z0-9_.-]+$ ]] || return 1
  endpoint_info="$(asterisk_cli_output "pjsip show endpoint $endpoint")"
  printf '%s\n' "$endpoint_info" | awk -v wanted="$endpoint" '
function trim(value) {
  sub(/^[[:space:]]+/, "", value)
  sub(/[[:space:]]+$/, "", value)
  return value
}
{
  separator = index($0, ":")
  if (separator == 0 || tolower(trim(substr($0, 1, separator - 1))) != "endpoint") {
    next
  }
  value = trim(substr($0, separator + 1))
  split(value, columns, /[[:space:]]+/)
  split(columns[1], endpoint_parts, "/")
  if (endpoint_parts[1] == wanted) {
    found = 1
  }
}
END {
  exit(found ? 0 : 1)
}'
}

pjsip_dial_string_endpoints_available() {
  local dial_string="$1"
  local channel endpoint
  local -a channels

  pjsip_dial_string_supported "$dial_string" || return 1
  IFS='&' read -r -a channels <<<"$dial_string"
  for channel in "${channels[@]}"; do
    channel="$(printf '%s' "$channel" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    endpoint="${channel#*/}"
    endpoint="${endpoint%%/*}"
    if ! pjsip_endpoint_available "$endpoint"; then
      log "PJSIP paging route component does not resolve to an active endpoint object: $channel"
      return 1
    fi
  done
}

verify_registered_endpoint_route() {
  local extension="$1"
  local device_route device_aor dial_string route_source

  [[ "$extension" =~ ^[0-9]+$ ]] || {
    log "Refusing to validate a nonnumeric paging extension: $extension"
    return 1
  }

  # Match the managed dialplan: explicit registered contacts are preferred so
  # Page creates one conference participant per contact instead of allowing a
  # single endpoint Dial operation to cancel sibling registrations.
  dial_string="$(asterisk_labeled_value "dialplan eval function PJSIP_DIAL_CONTACTS(${extension})" "Result")"
  route_source="PJSIP_DIAL_CONTACTS(${extension})"
  device_route=""
  device_aor=""
  if [ -z "$dial_string" ]; then
    device_route="$(asterisk_labeled_value "database get DEVICE ${extension}/dial" "Value")"
    if [[ "$device_route" =~ ^PJSIP/([A-Za-z0-9_.-]+)(/.*)?$ ]]; then
      device_aor="${BASH_REMATCH[1]}"
    fi
    if [ -n "$device_aor" ] && [ "$device_aor" != "$extension" ]; then
      dial_string="$(asterisk_labeled_value "dialplan eval function PJSIP_DIAL_CONTACTS(${device_aor})" "Result")"
      route_source="PJSIP_DIAL_CONTACTS(${device_aor}) from AstDB DEVICE/${extension}/dial"
    fi
  fi
  if [ -z "$dial_string" ]; then
    dial_string="$device_route"
    route_source="AstDB DEVICE/${extension}/dial fallback"
  fi
  if [ -z "$dial_string" ]; then
    dial_string="PJSIP/${extension}"
    route_source="PJSIP endpoint fallback"
  fi

  if ! pjsip_dial_string_supported "$dial_string"; then
    log "Registered endpoint $extension resolves through $route_source to a non-PJSIP paging route: $dial_string"
    log "The module will not rewrite DEVICE routes or SIP peers. Configure extension $extension as a PJSIP endpoint before installing."
    return 1
  fi
  if ! pjsip_dial_string_endpoints_available "$dial_string"; then
    log "Registered endpoint $extension resolves through $route_source, but one or more referenced PJSIP endpoint objects are unavailable."
    log "The module will not rewrite DEVICE routes or SIP peers. Repair the FreePBX endpoint route, then rerun the installer."
    return 1
  fi
  log "Registered endpoint $extension has a PJSIP-capable paging route with verified endpoint objects via $route_source."
}

endpoint_inventory_records() {
  local inventory_file="$1"
  python3 - "$inventory_file" <<'PY'
import json
import sys

supported_formats = {
    "yealink", "yealink_text", "cisco", "poly", "grandstream",
    "fanvil", "snom", "aastra", "sangoma", "avaya", "vtech",
    "ale", "panasonic", "generic", "unknown",
}

with open(sys.argv[1], encoding="utf-8") as handle:
    inventory = json.load(handle)
if not isinstance(inventory, dict):
    raise SystemExit("endpoint inventory is not a JSON object")

for extension, details in sorted(inventory.items()):
    if not isinstance(extension, str) or not isinstance(details, dict):
        raise SystemExit("endpoint inventory contains an invalid entry")
    if not extension.isdigit():
        # The notification module intentionally targets numeric FreePBX
        # extensions, not trunks or arbitrary alphanumeric PJSIP objects.
        continue
    try:
        contacts = int(details.get("contacts") or 0)
    except (TypeError, ValueError):
        raise SystemExit(f"endpoint {extension} has an invalid contact count")
    phone_format = str(details.get("format") or "").strip().lower()
    formats = details.get("formats") or [phone_format]
    if not isinstance(formats, list):
        raise SystemExit(f"endpoint {extension} has an invalid format list")
    normalized_formats = [str(value or "").strip().lower() for value in formats]
    if contacts < 1:
        raise SystemExit(f"endpoint {extension} has no registered contacts")
    if phone_format not in supported_formats or any(value not in supported_formats for value in normalized_formats):
        raise SystemExit(f"endpoint {extension} has an unsupported phone format")
    user_agent = " ".join(str(details.get("user_agent") or "").replace("\t", " ").split())[:300]
    unique_formats = sorted(set(normalized_formats))
    unknown = "1" if phone_format == "unknown" or "unknown" in unique_formats else "0"
    mixed = "1" if len(unique_formats) > 1 else "0"
    print("\t".join((
        extension,
        phone_format,
        str(contacts),
        unknown,
        mixed,
        ",".join(unique_formats),
        user_agent,
    )))
PY
}

mixed_endpoint_visual_route_supported() {
  local mixed_format="$1"
  local routing_mode="$2"
  [ "$mixed_format" != "1" ] || [ "$routing_mode" = "contact_uri" ]
}

preflight_platform() {
  local apache_module apache_modules available_kb check_path required_kb utility
  for check_path in /var/lib/asterisk /usr/local /var/www /tmp; do
    case "$check_path" in
      /var/lib/asterisk|/usr/local) required_kb=524288 ;;
      *) required_kb=65536 ;;
    esac
    available_kb="$(df -Pk "$check_path" | awk 'NR == 2 {print $4}')"
    if ! [[ "$available_kb" =~ ^[0-9]+$ ]] || [ "$available_kb" -lt "$required_kb" ]; then
      log "Insufficient free space on the filesystem containing $check_path."
      exit 1
    fi
  done
  for utility in timeout runuser flock readlink; do
    command -v "$utility" >/dev/null || {
      log "Required system utility is unavailable: $utility"
      exit 1
    }
  done
  /usr/sbin/apache2ctl configtest >>"$LOG_FILE" 2>&1 || {
    log "Apache configuration validation failed before installation. See $LOG_FILE."
    exit 1
  }
  systemctl is-active --quiet apache2 || {
    log "Apache is not active."
    exit 1
  }
  systemctl is-active --quiet cron || {
    log "The cron scheduler is not active."
    exit 1
  }
  apache_modules="$(/usr/sbin/apache2ctl -M 2>/dev/null)"
  for apache_module in rewrite_module setenvif_module; do
    printf '%s\n' "$apache_modules" | grep -Fq "$apache_module" || {
      log "Required Apache module is not loaded: $apache_module"
      exit 1
    }
  done
  asterisk -rx "core show version" >>"$LOG_FILE" 2>&1 || {
    log "The Asterisk control socket is unavailable. Start Asterisk before installing."
    exit 1
  }
  verify_supported_asterisk_directories || {
    log "Asterisk uses paths this release does not support; no module files were activated."
    exit 1
  }
  validate_piper_wrapper_ownership /usr/local/bin/piper || exit 1
  local module_name
  for module_name in \
    res_pjsip.so res_pjsip_session.so chan_pjsip.so res_pjsip_notify.so \
    pbx_spool.so format_wav.so; do
    ensure_asterisk_module_loaded "$module_name" || exit 1
  done
  asterisk_channel_type_available Local || {
    log "Required Asterisk Local channel driver is unavailable."
    exit 1
  }
  ensure_required_asterisk_capabilities || {
    log "Required Asterisk paging capabilities are unavailable before module activation."
    exit 1
  }
  verify_asterisk_module_startup_persistence || exit 1
  mkdir -p /var/spool/asterisk/tmp /var/spool/asterisk/outgoing /var/spool/asterisk/outgoing_done
  chown asterisk:asterisk /var/spool/asterisk/tmp /var/spool/asterisk/outgoing /var/spool/asterisk/outgoing_done
  chmod 0775 /var/spool/asterisk/tmp /var/spool/asterisk/outgoing
  chmod 0750 /var/spool/asterisk/outgoing_done
}

validate_piper_wrapper_ownership() {
  local wrapper="${1:-/usr/local/bin/piper}"
  local target
  if [ ! -e "$wrapper" ] && [ ! -L "$wrapper" ]; then
    return 0
  fi
  if [ -L "$wrapper" ]; then
    target="$(readlink "$wrapper" 2>/dev/null || true)"
    case "$target" in
      /usr/local/bin/sls_mass_notify/piper/venv/bin/piper|/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper)
        return 0
        ;;
    esac
  elif [ -f "$wrapper" ] && grep -Eq 'sls_mass_notify/piper|SLS_Mass_Notifications_Plugin/piper' "$wrapper" 2>/dev/null; then
    return 0
  fi
  log "$wrapper already belongs to another application. SLS Mass Notify did not overwrite it."
  log "Move or rename that wrapper explicitly, then rerun the installer if SLS should own the compatibility path."
  return 1
}

install_bootstrap_dependencies() {
  local -a missing_packages=()

  [ -x /usr/bin/php ] || missing_packages+=(php-cli)
  if [ -x /usr/bin/php ]; then
    /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' >/dev/null 2>&1 \
      || missing_packages+=(php-common)
  else
    missing_packages+=(php-common)
  fi
  { [ -x /usr/bin/flock ] && [ -x /usr/sbin/runuser ]; } || missing_packages+=(util-linux)
  { [ -x /usr/bin/timeout ] && [ -x /usr/bin/readlink ]; } || missing_packages+=(coreutils)

  if [ "${#missing_packages[@]}" -gt 0 ]; then
    command -v apt-get >/dev/null 2>&1 || {
      log "Missing installation bootstrap prerequisites and no supported Debian package manager is available: ${missing_packages[*]}"
      exit 1
    }
    log "Installing missing installation bootstrap prerequisites: ${missing_packages[*]}"
    refresh_apt_metadata || {
      log "Unable to refresh Debian package metadata for bootstrap prerequisites. See $LOG_FILE."
      exit 1
    }
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends --no-remove "${missing_packages[@]}"
  fi

  for bootstrap_utility in /usr/bin/php /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/sbin/runuser; do
    [ -x "$bootstrap_utility" ] || {
      log "A required installation bootstrap prerequisite is unavailable after dependency installation: $bootstrap_utility"
      exit 1
    }
  done
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials. Install php-common, then rerun the installer."
    exit 1
  }
}

install_dependencies() {
  local package
  local -a missing_packages=()
  add_missing_package() {
    local candidate="$1"
    local existing
    for existing in "${missing_packages[@]:-}"; do
      [ "$existing" = "$candidate" ] && return 0
    done
    missing_packages+=("$candidate")
  }

  if command -v apt-get >/dev/null; then
    [ -x /usr/bin/curl ] || add_missing_package curl
    [ -x /usr/bin/wget ] || add_missing_package wget
    [ -r /etc/ssl/certs/ca-certificates.crt ] || add_missing_package ca-certificates
    [ -x /usr/bin/gpg ] || add_missing_package gnupg
    [ -x /usr/bin/python3 ] || add_missing_package python3
    if [ -x /usr/bin/python3 ]; then
      /usr/bin/python3 -c 'import venv' >/dev/null 2>&1 || add_missing_package python3-venv
      /usr/bin/python3 -m pip --version >/dev/null 2>&1 || add_missing_package python3-pip
    else
      add_missing_package python3-venv
      add_missing_package python3-pip
    fi
    { [ -x /usr/bin/sox ] && [ -x /usr/bin/soxi ]; } || add_missing_package sox
    { [ -x /usr/bin/convert ] && [ -x /usr/bin/identify ]; } || add_missing_package imagemagick
    [ -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf ] || add_missing_package fonts-dejavu-core
    [ -x /usr/bin/tar ] || add_missing_package tar
    [ -x /usr/bin/php ] || add_missing_package php-cli
    if [ -x /usr/bin/php ]; then
      /usr/bin/php -r 'exit(extension_loaded("mbstring") && function_exists("mb_strlen") && function_exists("mb_substr") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package php-mbstring
      /usr/bin/php -r 'exit(function_exists("posix_getpwnam") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package php-common
      /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package php-common
    else
      add_missing_package php-mbstring
    fi
    { [ -x /usr/bin/crontab ] && [ -x /usr/sbin/cron ]; } || add_missing_package cron
    { [ -x /usr/bin/flock ] && [ -x /usr/sbin/runuser ]; } || add_missing_package util-linux
    { [ -x /usr/bin/timeout ] && [ -x /usr/bin/readlink ]; } || add_missing_package coreutils

    if [ "${#missing_packages[@]}" -gt 0 ]; then
      log "Installing missing runtime prerequisites: ${missing_packages[*]}"
      refresh_apt_metadata || {
        log "Unable to refresh Debian package metadata for missing prerequisites. See $LOG_FILE."
        exit 1
      }
      DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends --no-remove "${missing_packages[@]}"
    else
      log "Runtime package prerequisites are already available; skipping apt metadata refresh."
    fi
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
    { command -v convert >/dev/null && command -v identify >/dev/null; } || {
      log "ImageMagick is required for phone alert images."
      exit 1
    }
  fi
  [ -x /usr/bin/php ] || {
    log "The scheduling worker requires the canonical /usr/bin/php CLI executable. Install php-cli, then rerun the installer."
    exit 1
  }
  /usr/bin/php -r 'exit(extension_loaded("mbstring") && function_exists("mb_strlen") && function_exists("mb_substr") ? 0 : 1);' || {
    log "The PHP mbstring extension is required for safe alert text validation. Install php-mbstring, then rerun the installer."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("posix_getpwnam") ? 0 : 1);' || {
    log "PHP POSIX support is required to resolve the configured FreePBX service account safely. Install php-common, then rerun the installer."
    exit 1
  }
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials. Install php-common, then rerun the installer."
    exit 1
  }
  [ -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf ] || {
    log "The DejaVu Sans Bold font required for phone alert images is unavailable after dependency installation."
    exit 1
  }
  for package in /usr/bin/curl /usr/bin/wget /usr/bin/gpg /usr/bin/python3 /usr/bin/sox /usr/bin/soxi /usr/bin/convert /usr/bin/identify /usr/bin/tar /usr/bin/crontab /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/sbin/runuser; do
    [ -x "$package" ] || {
      log "A required runtime prerequisite is still unavailable after dependency installation: $package"
      exit 1
    }
  done
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
  elif [ "$(basename "$TGZ")" = "slsmassnotifyserver-0.1.0.tgz" ] && [ "$actual_sha" != "$EXPECTED_TGZ_SHA256" ]; then
		log "$TGZ does not match the current slsmassnotifyserver-0.1.0 package."
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
    if (root.findtext("version") or "").strip() != "0.1.0":
        raise SystemExit("module.xml does not contain the expected 0.1.0 version")
PY
}

prepare_settings_lock() {
  [ ! -L "$CONFIG_FILE" ] || {
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  }
  [ -e "$CONFIG_FILE" ] || return 0
  SETTINGS_LOCK_PATH="$SETTINGS_LOCK" /usr/bin/python3 - <<'PY'
import os
import pwd
import stat

path = os.environ["SETTINGS_LOCK_PATH"]
parts = [part for part in path.split("/") if part]
if not path.startswith("/") or not parts or "\x00" in path:
    raise SystemExit("invalid settings-lock path")
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
file_flags = os.O_RDWR | os.O_CLOEXEC | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
parent_fd = os.open("/", directory_flags)
try:
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    file_fd = os.open(parts[-1], file_flags, 0o640, dir_fd=parent_fd)
    try:
        metadata = os.fstat(file_fd)
        if not stat.S_ISREG(metadata.st_mode):
            raise SystemExit("settings lock is not a regular file")
        account = pwd.getpwnam("asterisk")
        os.fchmod(file_fd, 0o640)
        os.fchown(file_fd, account.pw_uid, account.pw_gid)
    finally:
        os.close(file_fd)
finally:
    os.close(parent_fd)
PY
}

acquire_settings_coordination() {
  [ ! -L "$CONFIG_FILE" ] || {
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  }
  [ -e "$CONFIG_FILE" ] || return 0
  prepare_settings_lock
  [ ! -L "$SETTINGS_LOCK" ] && [ -f "$SETTINGS_LOCK" ] || {
    log "Refusing to use an unsafe central-configuration lock file."
    exit 1
  }
  exec {CONFIG_LOCK_FD}<>"$SETTINGS_LOCK"
  flock -x "$CONFIG_LOCK_FD"
  lock_fd_identity="$(stat -Lc '%d:%i' "/proc/$$/fd/$CONFIG_LOCK_FD" 2>/dev/null || true)"
  lock_path_identity="$(stat -Lc '%d:%i' "$SETTINGS_LOCK" 2>/dev/null || true)"
  if [ -z "$lock_fd_identity" ] || [ "$lock_fd_identity" != "$lock_path_identity" ] || [ -L "$SETTINGS_LOCK" ]; then
    log "The central-configuration lock path changed while the installer acquired it."
    exit 1
  fi
}

safe_config_snapshot() {
  source_path="$1"
  snapshot_path="$2"
  CONFIG_SOURCE_PATH="$source_path" CONFIG_SNAPSHOT_PATH="$snapshot_path" /usr/bin/python3 - <<'PY'
import hashlib
import os
import stat

source_path = os.environ["CONFIG_SOURCE_PATH"]
snapshot_path = os.environ["CONFIG_SNAPSHOT_PATH"]
limit = 16 * 1024 * 1024

def open_regular(path, flags):
    parts = [part for part in path.split("/") if part]
    if not path.startswith("/") or not parts or "\x00" in path:
        raise RuntimeError("invalid path")
    directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
    parent_fd = os.open("/", directory_flags)
    try:
        for component in parts[:-1]:
            next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
            os.close(parent_fd)
            parent_fd = next_fd
        file_fd = os.open(parts[-1], flags | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
    finally:
        os.close(parent_fd)
    if not stat.S_ISREG(os.fstat(file_fd).st_mode):
        os.close(file_fd)
        raise RuntimeError("protected config is not a regular file")
    return file_fd

source_fd = open_regular(source_path, os.O_RDONLY)
snapshot_fd = open_regular(snapshot_path, os.O_WRONLY | os.O_TRUNC)
digest = hashlib.sha256()
total = 0
try:
    while True:
        chunk = os.read(source_fd, 1024 * 1024)
        if not chunk:
            break
        total += len(chunk)
        if total > limit:
            raise RuntimeError("protected config exceeds the size limit")
        digest.update(chunk)
        view = memoryview(chunk)
        while view:
            written = os.write(snapshot_fd, view)
            view = view[written:]
    os.fsync(snapshot_fd)
finally:
    os.close(snapshot_fd)
    os.close(source_fd)
print(digest.hexdigest())
PY
}

safe_config_hash() {
  CONFIG_SOURCE_PATH="$1" /usr/bin/python3 - <<'PY'
import hashlib
import os
import stat

path = os.environ["CONFIG_SOURCE_PATH"]
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
    file_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
finally:
    os.close(parent_fd)
try:
    metadata = os.fstat(file_fd)
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > 16 * 1024 * 1024:
        raise SystemExit(3)
    digest = hashlib.sha256()
    while True:
        chunk = os.read(file_fd, 1024 * 1024)
        if not chunk:
            break
        digest.update(chunk)
finally:
    os.close(file_fd)
print(digest.hexdigest())
PY
}

safe_config_restore() {
  snapshot_path="$1"
  destination_path="$2"
  CONFIG_SNAPSHOT_PATH="$snapshot_path" CONFIG_DESTINATION_PATH="$destination_path" /usr/bin/python3 - <<'PY'
import hashlib
import os
import pwd
import secrets
import stat

source_path = os.environ["CONFIG_SNAPSHOT_PATH"]
destination_path = os.environ["CONFIG_DESTINATION_PATH"]
limit = 16 * 1024 * 1024
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
file_nofollow = os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0)

def open_parent(path):
    parts = [part for part in path.split("/") if part]
    if not path.startswith("/") or not parts or "\x00" in path:
        raise RuntimeError("invalid path")
    parent_fd = os.open("/", directory_flags)
    for component in parts[:-1]:
        next_fd = os.open(component, directory_flags, dir_fd=parent_fd)
        os.close(parent_fd)
        parent_fd = next_fd
    return parent_fd, parts[-1]

source_parent_fd, source_name = open_parent(source_path)
try:
    source_fd = os.open(source_name, os.O_RDONLY | os.O_NONBLOCK | file_nofollow, dir_fd=source_parent_fd)
finally:
    os.close(source_parent_fd)
if not stat.S_ISREG(os.fstat(source_fd).st_mode):
    os.close(source_fd)
    raise SystemExit("config snapshot is not a regular file")

destination_parent_fd, destination_name = open_parent(destination_path)
temporary_name = ".mass-notifications.config.restore." + secrets.token_hex(8)
temporary_fd = -1
try:
    temporary_fd = os.open(
        temporary_name,
        os.O_WRONLY | os.O_CREAT | os.O_EXCL | file_nofollow,
        0o600,
        dir_fd=destination_parent_fd,
    )
    total = 0
    digest = hashlib.sha256()
    while True:
        chunk = os.read(source_fd, 1024 * 1024)
        if not chunk:
            break
        total += len(chunk)
        if total > limit:
            raise RuntimeError("config snapshot exceeds the size limit")
        digest.update(chunk)
        view = memoryview(chunk)
        while view:
            written = os.write(temporary_fd, view)
            view = view[written:]
    account = pwd.getpwnam("asterisk")
    os.fchmod(temporary_fd, 0o640)
    os.fchown(temporary_fd, account.pw_uid, account.pw_gid)
    os.fsync(temporary_fd)
    os.close(temporary_fd)
    temporary_fd = -1
    os.replace(temporary_name, destination_name, src_dir_fd=destination_parent_fd, dst_dir_fd=destination_parent_fd)
    os.fsync(destination_parent_fd)
    restored_fd = os.open(destination_name, os.O_RDONLY | os.O_NONBLOCK | file_nofollow, dir_fd=destination_parent_fd)
    try:
        metadata = os.fstat(restored_fd)
        if (
            not stat.S_ISREG(metadata.st_mode)
            or stat.S_IMODE(metadata.st_mode) != 0o640
            or metadata.st_uid != account.pw_uid
            or metadata.st_gid != account.pw_gid
        ):
            raise RuntimeError("restored config metadata is invalid")
        restored = hashlib.sha256()
        while True:
            chunk = os.read(restored_fd, 1024 * 1024)
            if not chunk:
                break
            restored.update(chunk)
        if restored.digest() != digest.digest():
            raise RuntimeError("restored config digest mismatch")
    finally:
        os.close(restored_fd)
finally:
    if temporary_fd >= 0:
        os.close(temporary_fd)
    try:
        os.unlink(temporary_name, dir_fd=destination_parent_fd)
    except FileNotFoundError:
        pass
    os.close(destination_parent_fd)
    os.close(source_fd)
PY
}

snapshot_config() {
  if [ -L "$CONFIG_FILE" ]; then
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  fi
  [ -r "$CONFIG_FILE" ] || return 0
  CONFIG_SNAPSHOT="$(mktemp /tmp/slsmassnotifyserver-config.XXXXXX)"
  if ! CONFIG_HASH_BEFORE="$(safe_config_snapshot "$CONFIG_FILE" "$CONFIG_SNAPSHOT")"; then
    rm -f "$CONFIG_SNAPSHOT"
    CONFIG_SNAPSHOT=""
    log "Refusing to install because the protected central configuration could not be opened safely without following symbolic links."
    exit 1
  fi
}

validate_preserved_config_prerequisites() {
  [ -n "$CONFIG_HASH_BEFORE" ] || return 0
  if ! CONFIG_PATH="$CONFIG_SNAPSHOT" /usr/bin/php -r '
$path = getenv("CONFIG_PATH");
$settings = json_decode((string)@file_get_contents($path), true);
if (!is_array($settings)) {
    exit(1);
}
$ami = $settings["ami"] ?? null;
if (!is_array($ami)) {
    exit(2);
}
$username = trim((string)($ami["username"] ?? ""));
$password = trim((string)($ami["password"] ?? ""));
if (!preg_match("/^[a-z0-9_.-]{1,64}$/", $username)) {
    exit(3);
}
if (!preg_match("/^[\\x21-\\x7e]{1,128}$/", $password)) {
    exit(4);
}
exit(0);
'; then
    log "The preserved central config has invalid JSON or AMI credentials. Restore a valid mass-notifications.config before upgrading; the installer did not alter it."
    exit 1
  fi
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
  current_hash="$(safe_config_hash "$CONFIG_FILE" 2>/dev/null || true)"
  if [ "$current_hash" = "$CONFIG_HASH_BEFORE" ]; then
    rm -f "$CONFIG_SNAPSHOT"
    CONFIG_SNAPSHOT=""
    return 0
  fi
  current_snapshot="$(mktemp /tmp/slsmassnotifyserver-config-current.XXXXXX)"
  if safe_config_snapshot "$CONFIG_FILE" "$current_snapshot" >/dev/null 2>&1; then
    describe_config_drift "$CONFIG_SNAPSHOT" "$current_snapshot"
  else
    log "Config drift: the live protected path became missing, non-regular, or unsafe."
  fi
  rm -f "$current_snapshot"
  if ! ensure_data_directory || ! safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE"; then
    log "CRITICAL: unable to safely restore the protected central config. The original root-owned snapshot remains at $CONFIG_SNAPSHOT."
    CONFIG_SNAPSHOT=""
    exit 1
  fi
  rm -f "$CONFIG_SNAPSHOT"
  CONFIG_SNAPSHOT=""
  refresh_module_install || true
  log "The installer detected an unexpected central config change, restored the original config, and stopped."
  exit 1
}

module_known() {
  fwconsole ma list 2>/dev/null | grep -Eq "\\|[[:space:]]*$1[[:space:]]*\\|"
}

stage_module_directory() {
  if module_known "$MODULE"; then
    HAD_EXISTING_MODULE=1
    if fwconsole ma list 2>/dev/null \
      | grep -Eq "\\|[[:space:]]*${MODULE}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|"; then
      MODULE_WAS_ENABLED=1
    fi
    log "Existing SLS Mass Notify module detected; preserving config and preparing a recoverable upgrade."
  fi
  STAGING_DIR="$(mktemp -d /tmp/sls-mass-notify-stage.XXXXXX)"
  tar -xzf "$TGZ" -C "$STAGING_DIR"
  if [ ! -d "$STAGING_DIR/$MODULE" ] || [ ! -r "$STAGING_DIR/$MODULE/module.xml" ]; then
    log "The staged module tree is incomplete."
    exit 1
  fi
  while IFS= read -r script; do
    php -l "$script" >/dev/null || {
      log "The staged module contains an invalid PHP file: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.php' -print)
  while IFS= read -r script; do
    bash -n "$script" || {
      log "The staged module contains an invalid shell script: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.sh' -print)
  while IFS= read -r script; do
    python3 -c 'import pathlib,sys; compile(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"), sys.argv[1], "exec")' "$script" || {
      log "The staged module contains an invalid Python script: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.py' -print)
}

activate_staged_module() {
  local target="/var/www/html/admin/modules/$MODULE"
  MODULE_BACKUP_DIR="$(mktemp -d /tmp/sls-mass-notify-module-backup.XXXXXX)"
  if [ -d "$target" ]; then
    mv "$target" "$MODULE_BACKUP_DIR/$MODULE"
  fi
  if ! mv "$STAGING_DIR/$MODULE" "$target"; then
    [ -d "$MODULE_BACKUP_DIR/$MODULE" ] && mv "$MODULE_BACKUP_DIR/$MODULE" "$target"
    log "Unable to activate the staged module directory."
    exit 1
  fi
  MODULE_ACTIVATED=1
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

module_registered_at_expected_version() {
  SLS_MASS_NOTIFY_MODULE="$MODULE" php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE") ?: "slsmassnotifyserver";
$stmt = \FreePBX::Database()->prepare("SELECT version FROM modules WHERE modulename = ? LIMIT 1");
$stmt->execute([$module]);
$version = $stmt->fetchColumn();
exit(is_string($version) && trim($version) === "0.1.0" ? 0 : 1);
' >>"$LOG_FILE" 2>&1
}

refresh_module_install() {
  log "Refreshing SLS Mass Notify runtime integration."
  if ! SLS_MASS_NOTIFY_DEFER_SIGNING=1 php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >>"$LOG_FILE" 2>&1; then
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

verify_piper_voices() {
  local voice_dir="$DATA_DIR/piper/voices"
  local synthesis_file model
  while read -r expected filename; do
    printf '%s  %s\n' "$expected" "$voice_dir/$filename" | sha256sum -c - >/dev/null || {
      log "Required Piper voice failed checksum validation: $filename"
      exit 1
    }
  done <<'EOF'
f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375 en_US-lessac-low.onnx
45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a en_US-lessac-low.onnx.json
a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3 en_US-amy-low.onnx
2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb en_US-amy-low.onnx.json
8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf en_US-ryan-low.onnx
b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60 en_US-ryan-low.onnx.json
EOF
  for model in en_US-lessac-low.onnx en_US-amy-low.onnx en_US-ryan-low.onnx; do
    synthesis_file="$DATA_DIR/sounds/tts/installer-voice-check-${model%.onnx}-$$.wav"
    rm -f "$synthesis_file"
    if ! printf '%s\n' 'SLS voice check.' | runuser -u asterisk -- /usr/bin/timeout 90 /usr/local/bin/piper \
      --model "$voice_dir/$model" --output-file "$synthesis_file" >>"$LOG_FILE" 2>&1; then
      rm -f "$synthesis_file"
      log "Piper could not synthesize audio with $model. See $LOG_FILE."
      exit 1
    fi
    soxi "$synthesis_file" >/dev/null 2>&1 || {
      rm -f "$synthesis_file"
      log "Piper produced an invalid WAV with $model."
      exit 1
    }
    rm -f "$synthesis_file"
  done
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
      /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php \
      /usr/local/bin/sls_mass_notify/sls_mass_notify_test.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_uninstall.sh \
      /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh \
	  /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py \
	  /usr/local/bin/sls_mass_notify/sls_branded_email.py \
	  /usr/local/bin/sls_mass_notify/sls_branded_discord.py \
	  /usr/local/bin/sls_mass_notify/sls_notification_destinations.py \
	  /usr/local/bin/sls_mass_notify/sls_system_notifications.py \
	  /usr/local/bin/sls_mass_notify/sls_nws_status.py \
      /usr/local/bin/sls_mass_notify/sls_notify.py \
      /usr/local/bin/sls_mass_notify/sls_config.py 2>/dev/null || true
  fi

  mkdir -p /var/spool/asterisk/tmp /var/spool/asterisk/outgoing /var/spool/asterisk/outgoing_done
  chown asterisk:asterisk /var/spool/asterisk/tmp /var/spool/asterisk/outgoing /var/spool/asterisk/outgoing_done
  chmod 0775 /var/spool/asterisk/tmp /var/spool/asterisk/outgoing
  chmod 0750 /var/spool/asterisk/outgoing_done

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
    file_fd = os.open(parts[-1], os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
finally:
    os.close(parent_fd)
try:
    metadata = os.fstat(file_fd)
    if not stat.S_ISREG(metadata.st_mode):
        raise SystemExit(3)
    account = pwd.getpwnam("asterisk")
    os.fchmod(file_fd, 0o640)
    os.fchown(file_fd, account.pw_uid, account.pw_gid)
    verified = os.fstat(file_fd)
    if stat.S_IMODE(verified.st_mode) != 0o640 or verified.st_uid != account.pw_uid or verified.st_gid != account.pw_gid:
        raise SystemExit(4)
finally:
    os.close(file_fd)
PY
  then
    log "Protected central configuration permissions or ownership could not be secured."
    exit 1
  fi
}

validate_ami_health_file() {
  python3 - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    health = json.load(handle)
if health.get("status") != "ok" or health.get("ami") != "authenticated" or health.get("ping") != "pong":
    raise SystemExit("AMI health result is incomplete")
if health.get("pjsip_show_contacts") not in {"authorized", "authorized_empty"}:
    raise SystemExit("AMI PJSIPShowContacts authorization was not confirmed")
if health.get("pjsip_notify") != "authorized":
    raise SystemExit("AMI PJSIPNotify authorization was not confirmed")
PY
}

verify_ami_with_repair() {
  local attempt
  for attempt in 1 2 3; do
    if /usr/bin/timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --ami-health-json \
      >/tmp/sls-mass-notify-ami-health.json 2>/tmp/sls-mass-notify-ami-health.err; then
      if validate_ami_health_file /tmp/sls-mass-notify-ami-health.json >>"$LOG_FILE" 2>&1
      then
        return 0
      fi
    fi
    cat /tmp/sls-mass-notify-ami-health.err >>"$LOG_FILE" 2>/dev/null || true
    log "SLS AMI health check attempt $attempt failed; rebuilding the loopback manager integration."
    refresh_module_install >>"$LOG_FILE" 2>&1 || true
    asterisk -rx "manager reload" >>"$LOG_FILE" 2>&1 || true
    sleep 2
  done
  log "SLS AMI authentication, Ping, PJSIPShowContacts, or PJSIPNotify authorization failed after automatic repair. See $LOG_FILE."
  return 1
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

verify_installed_payload_parity() {
  MODULE_ROOT="/var/www/html/admin/modules/$MODULE" python3 - <<'PY'
import filecmp
import os
import pathlib

module = pathlib.Path(os.environ["MODULE_ROOT"])
runtime = pathlib.Path("/usr/local/bin/sls_mass_notify")
expected_runtime = {}
for name in (
    "sls_mass_notify_nws_poll.sh",
    "sls_mass_notify_weather_poll.sh",
    "sls_mass_notify_schedule_worker.php",
    "sls_mass_notify_test.sh",
    "sls_mass_notify_update.sh",
    "sls_mass_notify_maintenance.sh",
    "sls_mass_notify_uninstall.sh",
    "sls_mass_notify_install_piper_voices.sh",
):
    expected_runtime[pathlib.PurePosixPath(name)] = module / "bin" / name
for source in (module / "bin" / "sls_mass_notify").rglob("*"):
    if not source.is_file() or "__pycache__" in source.parts or source.suffix == ".pyc":
        continue
    relative = pathlib.PurePosixPath(source.relative_to(module / "bin" / "sls_mass_notify").as_posix())
    expected_runtime[relative] = source

actual_runtime = {
    pathlib.PurePosixPath(path.relative_to(runtime).as_posix())
    for path in runtime.rglob("*")
    if path.is_file()
    and path.relative_to(runtime).parts[:1] != ("piper",)
    and "__pycache__" not in path.parts
    and path.suffix != ".pyc"
}
if actual_runtime != set(expected_runtime):
    missing = sorted(str(path) for path in set(expected_runtime) - actual_runtime)
    extra = sorted(str(path) for path in actual_runtime - set(expected_runtime))
    raise SystemExit(f"runtime manifest mismatch; missing={missing} extra={extra}")
for relative, source in expected_runtime.items():
    target = runtime / pathlib.Path(str(relative))
    if not target.is_file() or not filecmp.cmp(source, target, shallow=False):
        raise SystemExit(f"runtime file differs from packaged source: {relative}")

for source_root, target_root, label in (
    (module / "api" / "sipnotify", pathlib.Path("/var/www/html/api/sipnotify"), "desktop API"),
    (module / "api" / "sls-mass-notify", pathlib.Path("/var/www/html/api/sls-mass-notify"), "control API"),
    (module / "assets", pathlib.Path("/var/www/html/sls_mass_notify/assets"), "public assets"),
):
    expected = {
        pathlib.PurePosixPath(path.relative_to(source_root).as_posix()): path
        for path in source_root.rglob("*")
        if path.is_file()
    }
    actual = {
        pathlib.PurePosixPath(path.relative_to(target_root).as_posix())
        for path in target_root.rglob("*")
        if path.is_file()
    }
    if actual != set(expected):
        raise SystemExit(f"{label} manifest differs from packaged source")
    for relative, source in expected.items():
        target = target_root / pathlib.Path(str(relative))
        if not target.is_file() or not filecmp.cmp(source, target, shallow=False):
            raise SystemExit(f"{label} file differs from packaged source: {relative}")

signer_source = module / "bin" / "sign_sls_mass_notify_local_sig.sh"
signer_target = pathlib.Path("/usr/local/sbin/sign_sls_mass_notify_local_sig.sh")
if not signer_target.is_file() or not filecmp.cmp(signer_source, signer_target, shallow=False):
    raise SystemExit("local signer differs from packaged source")
PY
}

verify_desktop_sse_handshake() {
  local desktop_status=0
  php -r '
$path = "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config";
$settings = json_decode((string)@file_get_contents($path), true);
if (!is_array($settings) || !function_exists("openssl_decrypt")) {
    exit(1);
}
$selected = null;
foreach ((array)($settings["desktop_clients"] ?? []) as $client) {
    if (is_array($client) && !empty($client["enabled"])) {
        $selected = $client;
        break;
    }
}
if (!is_array($selected)) {
    exit(3);
}
$key = base64_decode((string)($settings["desktop_auth_key"] ?? ""), true);
if (!is_string($key) || strlen($key) !== 32) {
    exit(1);
}
$encoded = (string)($selected["password_enc"] ?? "");
$raw = strpos($encoded, "v1:") === 0 ? base64_decode(substr($encoded, 3), true) : false;
if (!is_string($raw) || strlen($raw) < 29) {
    exit(1);
}
$password = openssl_decrypt(
    substr($raw, 28),
    "aes-256-gcm",
    $key,
    OPENSSL_RAW_DATA,
    substr($raw, 0, 12),
    substr($raw, 12, 16)
);
$username = (string)($selected["username"] ?? "");
if (!is_string($password) || $password === "" || $username === "") {
    exit(1);
}
$hosts = ["127.0.0.1"];
$publicHost = strtolower(trim((string)($settings["public_pbx_host"] ?? "")));
if (preg_match("/^[a-z0-9.-]+$/", $publicHost)
    && !in_array($publicHost, ["127.0.0.1", "localhost"], true)) {
    $hosts[] = $publicHost;
}
foreach ($hosts as $host) {
    $hostHeader = $host === "127.0.0.1" ? "" : "Host: " . $host . "\r\n";
    $context = stream_context_create(["http" => [
        "method" => "GET",
        "header" => $hostHeader
            . "Authorization: Basic " . base64_encode($username . ":" . $password)
            . "\r\nAccept: text/event-stream\r\nConnection: close\r\n",
        "timeout" => 8,
        "ignore_errors" => true,
        "follow_location" => 0,
        "max_redirects" => 0,
    ], "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
        "peer_name" => $host,
        "SNI_enabled" => true,
    ]]);
    foreach (["http", "https"] as $scheme) {
        $body = @file_get_contents(
            $scheme . "://127.0.0.1/api/sipnotify/desktop/stream?stream_seconds=1",
            false,
            $context
        );
        $headers = implode("\n", $http_response_header ?? []);
        if (is_string($body)
            && stripos($headers, "Content-Type: text/event-stream") !== false
            && strpos($body, "event: authenticated") !== false
            && strpos($body, "\"transport\":\"live_sse\"") !== false) {
            exit(0);
        }
    }
}
exit(1);
' >>"$LOG_FILE" 2>&1 || desktop_status=$?
  if [ "$desktop_status" -eq 0 ]; then
    log "Desktop live SSE authentication handshake verified."
    return 0
  fi
  if [ "$desktop_status" -eq 3 ]; then
    log "Desktop live SSE handshake skipped because all desktop clients are disabled."
    return 0
  fi
  log "Desktop live SSE authentication handshake failed. See $LOG_FILE."
  return 1
}

verify_control_api_authentication() {
  local control_status=0
  php -r '
$path = "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config";
$settings = json_decode((string)@file_get_contents($path), true);
$control = is_array($settings["control_api"] ?? null) ? $settings["control_api"] : [];
if (empty($control["enabled"])) {
    exit(3);
}
$key = trim((string)($control["api_key"] ?? ""));
if ($key === "") {
    exit(1);
}
$hosts = ["127.0.0.1"];
$publicHost = strtolower(trim((string)($settings["public_pbx_host"] ?? "")));
if (preg_match("/^[a-z0-9.-]+$/", $publicHost)
    && !in_array($publicHost, ["127.0.0.1", "localhost"], true)) {
    $hosts[] = $publicHost;
}
$lastError = "";
foreach ($hosts as $host) {
    $hostHeader = $host === "127.0.0.1" ? "" : "Host: " . $host . "\r\n";
    $context = stream_context_create(["http" => [
        "method" => "GET",
        "header" => $hostHeader . "Authorization: Bearer " . $key
            . "\r\nAccept: application/json\r\nConnection: close\r\n",
        "timeout" => 8,
        "ignore_errors" => true,
        "follow_location" => 0,
        "max_redirects" => 0,
    ], "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
        "peer_name" => $host,
        "SNI_enabled" => true,
    ]]);
    foreach (["http", "https"] as $scheme) {
        $body = @file_get_contents(
            $scheme . "://127.0.0.1/api/sls-mass-notify/?resource=status",
            false,
            $context
        );
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded) && !empty($decoded["ok"]) && ($decoded["resource"] ?? "") === "status") {
            exit(0);
        }
        if (is_array($decoded) && ($decoded["error"] ?? "") === "ip_not_allowed") {
            exit(4);
        }
        if (is_array($decoded) && ($decoded["error"] ?? "") === "rate_limited") {
            exit(5);
        }
        if (is_array($decoded)) {
            $lastError = (string)($decoded["error"] ?? "");
        }
    }
}
exit($lastError === "unauthorized" ? 2 : 1);
' >>"$LOG_FILE" 2>&1 || control_status=$?
  case "$control_status" in
    0)
      log "Control API authenticated status request verified."
      ;;
    3)
      log "Control API authenticated request skipped because the API is disabled."
      ;;
    4)
      log "Control API authenticated request skipped because the configured allowlist excludes loopback."
      ;;
    5)
      log "Control API authenticated request skipped because the configured rate limit is already exhausted."
      ;;
    *)
      log "Control API authentication or Authorization-header forwarding failed. See $LOG_FILE."
      return 1
      ;;
  esac
}

verify_fresh_install_defaults() {
  [ -z "$CONFIG_HASH_BEFORE" ] || return 0
  CONFIG_PATH="$CONFIG_FILE" php -r '
$settings = json_decode((string)@file_get_contents(getenv("CONFIG_PATH")), true);
if (!is_array($settings)) {
    exit(1);
}
$setup = is_array($settings["setup"] ?? null) ? $settings["setup"] : [];
$lightning = is_array($settings["xweather"] ?? null) ? $settings["xweather"] : [];
$control = is_array($settings["control_api"] ?? null) ? $settings["control_api"] : [];
$ami = is_array($settings["ami"] ?? null) ? $settings["ami"] : [];
$checks = [
    (string)($settings["enabled"] ?? "") === "0",
    (string)($setup["completed"] ?? "") === "0",
    (string)($setup["beta_accepted"] ?? "") === "0",
    (string)($lightning["enabled"] ?? "") === "0",
    (int)($lightning["query_interval_minutes"] ?? 0) === 5,
    (string)($lightning["adaptive_free_tier"] ?? "") === "1",
    (int)($lightning["adaptive_grace_minutes"] ?? 0) === 60,
    (string)($lightning["quiet_hours_enabled"] ?? "") === "0",
    (int)($lightning["tts_volume"] ?? 0) === 25,
    (int)($settings["nws_tts_volume"] ?? 0) === 25,
    (int)($settings["announcement_tts_volume"] ?? 0) === 25,
    (string)($settings["announcement_timeout_mode"] ?? "") === "none",
    (int)($settings["announcement_timeout_seconds"] ?? 0) === 300,
    is_array($settings["scheduled_announcements"] ?? null) && count($settings["scheduled_announcements"]) === 0,
    basename((string)($settings["nws_piper_voice"] ?? "")) === "en_US-amy-low.onnx",
    basename((string)($settings["announcement_piper_voice"] ?? "")) === "en_US-lessac-low.onnx",
    (string)($settings["opening_tone"] ?? "") === "opening_Paging_Tone_Opening",
    (string)($settings["closing_tone"] ?? "") === "closing_Paging_Tone_Closing",
    (string)($settings["nws_opening_tone"] ?? "") === "opening_NWS_alert",
    (string)($settings["nws_closing_tone"] ?? "invalid") === "",
    (string)($lightning["opening_tone"] ?? "") === "opening_Lightning_alert",
    (string)($lightning["closing_tone"] ?? "invalid") === "",
    (string)($control["enabled"] ?? "") === "0",
    preg_match("/^[A-Za-z0-9_-]{24,128}$/", (string)($control["api_key"] ?? "")) === 1,
    preg_match("/^[A-Za-z0-9_-]{24,128}$/", (string)($ami["password"] ?? "")) === 1,
];
exit(!in_array(false, $checks, true) ? 0 : 1);
' || {
    log "Fresh central configuration does not contain the required safe defaults."
    exit 1
  }
}

ensure_local_signer() {
  local signer_source="${SLS_MASS_NOTIFY_SIGNER_SOURCE:-/var/www/html/admin/modules/$MODULE/bin/sign_sls_mass_notify_local_sig.sh}"
  local signer_target="${SLS_MASS_NOTIFY_SIGNER_TARGET:-/usr/local/sbin/sign_sls_mass_notify_local_sig.sh}"
  local signer_stage=""
  local target_identity=""
  local target_mode=""

  [ -f "$signer_source" ] && [ ! -L "$signer_source" ] || {
    log "Packaged local signer is missing or unsafe: $signer_source"
    return 1
  }
  [ ! -L "$signer_target" ] || {
    log "Refusing to replace a symbolic-link local signer: $signer_target"
    return 1
  }
  if [ -e "$signer_target" ] && [ ! -f "$signer_target" ]; then
    log "Refusing to replace a non-regular local signer: $signer_target"
    return 1
  fi
  if [ -f "$signer_target" ]; then
    target_identity="$(stat -c '%U:%G' "$signer_target" 2>/dev/null || true)"
    target_mode="$(stat -c '%a' "$signer_target" 2>/dev/null || true)"
  fi
  if [ ! -f "$signer_target" ] \
    || [ ! -x "$signer_target" ] \
    || [ "$target_identity" != "root:root" ] \
    || [ "$target_mode" != "755" ] \
    || ! cmp -s "$signer_source" "$signer_target"; then
    log "Repairing the PBX-local module signer from the installed package."
    signer_stage="$(mktemp "$(dirname "$signer_target")/.sign_sls_mass_notify_local_sig.XXXXXX")" || return 1
    if ! install -m 0755 -o root -g root "$signer_source" "$signer_stage" \
      || ! mv -f -- "$signer_stage" "$signer_target"; then
      rm -f -- "$signer_stage"
      return 1
    fi
  fi
  [ ! -L "$signer_target" ] \
    && [ -f "$signer_target" ] \
    && [ -x "$signer_target" ] \
    && [ "$(stat -c '%U:%G' "$signer_target" 2>/dev/null || true)" = "root:root" ] \
    && [ "$(stat -c '%a' "$signer_target" 2>/dev/null || true)" = "755" ] \
    && cmp -s "$signer_source" "$signer_target" || {
    log "The PBX-local module signer could not be restored exactly."
    return 1
  }
}

sign_and_verify_touched_modules() {
  local signer="/usr/local/sbin/sign_sls_mass_notify_local_sig.sh"
  local module_name attempt signed
  local -a modules_to_sign

  ensure_local_signer || exit 1

  modules_to_sign=("$MODULE")
  [ -d /var/www/html/admin/modules/dashboard ] && modules_to_sign+=("dashboard")
  [ -d /var/www/html/admin/modules/framework ] && modules_to_sign+=("framework")

  for module_name in "${modules_to_sign[@]}"; do
    signed=0
    for attempt in 1 2; do
      if /usr/bin/timeout --signal=TERM 360 "$signer" "$module_name" >>"$LOG_FILE" 2>&1; then
        signed=1
        break
      fi
      log "Local signing attempt $attempt of 2 failed for $module_name."
      [ "$attempt" -eq 2 ] || sleep 2
    done
    [ "$signed" -eq 1 ] || {
      log "Unable to locally sign $module_name. See $LOG_FILE."
      tail -100 "$LOG_FILE" 2>/dev/null || true
      exit 1
    }

    SLS_MASS_NOTIFY_MODULE="$module_name" php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE");
$gpg = \FreePBX::GPG();
$gpg->timeout = 30;
$result = $gpg->verifyModule($module);
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
$valid = is_array($result)
    && array_key_exists("status", $result)
    && is_int($result["status"])
    && $result["status"] === 129
    && array_key_exists("details", $result)
    && is_array($result["details"])
    && count($result["details"]) === 0;
if (!$valid) {
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
  verify_installed_payload_parity
  verify_fresh_install_defaults
  sign_and_verify_touched_modules
  verify_dashboard_integration
  php -l /var/www/html/admin/views/menu_items.php >/dev/null || {
    log "FreePBX menu template is invalid after Mass Notify placement was applied."
    exit 1
  }
  grep -Fq 'SLS Mass Notifications menu placement: keep Mass Notify after UCP/User Panel.' \
    /var/www/html/admin/views/menu_items.php || {
      log "This FreePBX menu template is not compatible with the Mass Notify top-level placement patch."
      exit 1
    }
  module_list="$(fwconsole ma list)"
  printf '%s\n' "$module_list" | grep -Ei 'slsmassnotifyserver|dashboard|framework|Module'
  printf '%s\n' "$module_list" | grep -Eq '\|[[:space:]]*slsmassnotifyserver[[:space:]]*\|[^|]*\|[[:space:]]*Enabled[[:space:]]*\|' || {
    log "The SLS Mass Notify module is not enabled after installation."
    exit 1
  }
  for required_module in framework dashboard backup recordings; do
    printf '%s\n' "$module_list" \
      | grep -Eq "\\|[[:space:]]*${required_module}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|" || {
        log "Required FreePBX module is not enabled after installation: $required_module"
        exit 1
      }
  done
  if ! php -r '
require "/etc/freepbx.conf";
$freepbx = \FreePBX::Create();
if (!$freepbx->Modules->checkStatus("backup")) {
    fwrite(STDERR, "FreePBX Backup is not enabled.\n");
    exit(1);
}
$available = $freepbx->Backup->getModules();
if (!is_array($available) || !isset($available["slsmassnotifyserver"])) {
    fwrite(STDERR, "FreePBX did not discover the Mass Notify native backup adapter.\n");
    exit(2);
}
$enrollment = $freepbx->Slsmassnotifyserver->ensureFreePbxBackupEnrollment();
$health = $freepbx->Slsmassnotifyserver->getFreePbxBackupHealth();
$jobs = (int)($enrollment["jobs"] ?? 0);
$enrolled = (int)($enrollment["enrolled"] ?? 0);
if (empty($enrollment["success"]) || $jobs !== $enrolled || ($health["state"] ?? "") !== "ok") {
    fwrite(STDERR, "Mass Notify backup enrollment verification failed.\n");
    exit(3);
}
if ($jobs === 0) {
    echo "Native FreePBX backup adapter verified; this PBX has no administrator-defined module backup jobs yet.\n";
} else {
    echo "Native FreePBX backup adapter verified and enrolled in {$enrolled} module backup job(s).\n";
}
exit(0);
' >>"$LOG_FILE" 2>&1; then
    log "Native FreePBX backup integration verification failed. See $LOG_FILE."
    exit 1
  fi
  ensure_required_asterisk_capabilities || {
    log "Required Asterisk paging capabilities disappeared after FreePBX reload."
    exit 1
  }
  verify_asterisk_module_startup_persistence || {
    log "Required Asterisk modules are not configured to survive an Asterisk restart."
    exit 1
  }
  audio_dialplan="$(asterisk -rx "dialplan show 1000@sls-alert-audio" 2>&1)"
  printf '%s\n' "$audio_dialplan"
  printf '%s\n' "$audio_dialplan" | grep -Fq 'Page(${SLS_DIAL},b(sls-alert-autoanswer^s^1(${EXTEN}))A(${SLS_SAFE_SOUND})inq,5)' || {
    log "The SLS audio paging context is missing its multi-contact Page/ConfBridge auto-answer and audio handler."
    exit 1
  }
  if printf '%s\n' "$audio_dialplan" | grep -Fq 'Dial(${SLS_DIAL}'; then
    log "The SLS audio paging context still uses first-answer Dial semantics instead of multi-contact Page semantics."
    exit 1
  fi
  dynamic_contacts_line="$(printf '%s\n' "$audio_dialplan" | grep -nFm1 'Set(SLS_DIAL=${PJSIP_DIAL_CONTACTS(${EXTEN})})' | cut -d: -f1)"
  device_fallback_line="$(printf '%s\n' "$audio_dialplan" | grep -nFm1 'Set(SLS_DIAL=${SLS_DEVICE_DIAL})' | cut -d: -f1)"
  if ! [[ "$dynamic_contacts_line" =~ ^[0-9]+$ && "$device_fallback_line" =~ ^[0-9]+$ ]] \
    || [ "$dynamic_contacts_line" -ge "$device_fallback_line" ]; then
    log "The SLS audio paging context does not prefer explicit PJSIP contacts before its AstDB endpoint fallback."
    exit 1
  fi
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
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'Set(SLS_AUTOANSWER_CONTACT=${CHANNEL(contact)})' || {
    log "The SLS PJSIP auto-answer context is missing per-contact user-agent discovery."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'PJSIP_AOR(${SLS_AUTOANSWER_AOR},contact)' || {
    log "The SLS PJSIP auto-answer context is missing its extension/AOR contact fallback."
    exit 1
  }
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq '${SLS_AUTOANSWER_UA:0:7}"="yealink"]?Set(SLS_ALERT_INFO=Intercom)' || {
    log "The SLS PJSIP auto-answer context is missing the Yealink Intercom Alert-Info policy."
    exit 1
  }
  command -v runuser >/dev/null 2>&1 || {
    log "runuser is required to verify Asterisk call-file spool access."
    exit 1
  }
  for spool_dir in /var/spool/asterisk/tmp /var/spool/asterisk/outgoing /var/spool/asterisk/outgoing_done; do
    [ -d "$spool_dir" ] || {
      log "Required Asterisk call-file spool directory is missing: $spool_dir"
      exit 1
    }
    if ! runuser -u asterisk -- test -w "$spool_dir" \
      || ! runuser -u asterisk -- test -x "$spool_dir"; then
      log "The asterisk service account cannot search and write to $spool_dir."
      exit 1
    fi
  done
  if [ "$(stat -c '%d' /var/spool/asterisk/tmp)" != "$(stat -c '%d' /var/spool/asterisk/outgoing)" ] \
    || [ "$(stat -c '%d' /var/spool/asterisk/outgoing)" != "$(stat -c '%d' /var/spool/asterisk/outgoing_done)" ]; then
    log "Asterisk's temporary, outgoing, and test-result call-file directories are on different filesystems."
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
  for writable_dir in "$DATA_DIR/sounds/tts" "$DATA_DIR/sounds/tones" /var/www/html/sls_mass_notify; do
    if ! runuser -u asterisk -- test -w "$writable_dir" \
      || ! runuser -u asterisk -- test -x "$writable_dir"; then
      log "The asterisk service account cannot search and write to $writable_dir."
      exit 1
    fi
  done
  for recording_file in \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Opening.wav \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Paging_Tone_Closing.wav \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_NWS_Alert.wav \
    /var/lib/asterisk/sounds/en/custom/SLS_Mass_Notify_Lightning_Alert.wav; do
    [ -r "$recording_file" ] || {
      log "Bundled System Recording is missing: $recording_file"
      exit 1
    }
    [ "$(soxi -r "$recording_file" 2>/dev/null || true)" = "8000" ] \
      && [ "$(soxi -c "$recording_file" 2>/dev/null || true)" = "1" ] \
      && [ "$(soxi -b "$recording_file" 2>/dev/null || true)" = "16" ] || {
        log "Bundled System Recording has an invalid Asterisk WAV format: $recording_file"
        exit 1
      }
  done
  php -r '
require "/etc/freepbx.conf";
$required = [
    "custom/SLS_Mass_Notify_Paging_Tone_Opening",
    "custom/SLS_Mass_Notify_Paging_Tone_Closing",
    "custom/SLS_Mass_Notify_NWS_Alert",
    "custom/SLS_Mass_Notify_Lightning_Alert",
];
$stmt = \FreePBX::Database()->prepare("SELECT COUNT(*) FROM recordings WHERE filename = ?");
foreach ($required as $filename) {
    $stmt->execute([$filename]);
    if ((int)$stmt->fetchColumn() < 1) {
        fwrite(STDERR, "Missing System Recordings entry: " . $filename . "\n");
        exit(1);
    }
}
exit(0);
' >>"$LOG_FILE" 2>&1 || {
    log "Bundled sounds were not registered with FreePBX System Recordings. See $LOG_FILE."
    exit 1
  }
  asterisk_file_formats="$(asterisk -rx "core show file formats" 2>&1)"
  printf '%s\n' "$asterisk_file_formats" | grep -Eq '(^|[[:space:]])wav([[:space:]]|$)' || {
    log "Asterisk WAV file-format support is unavailable."
    exit 1
  }
  [ -f /etc/apache2/conf-available/sls-mass-notify.conf ] \
    && [ -e /etc/apache2/conf-enabled/sls-mass-notify.conf ] \
    && grep -Fq '<Directory /var/www/html/api/sipnotify>' /etc/apache2/conf-available/sls-mass-notify.conf \
    && grep -Fq '<Directory /var/www/html/api/sls-mass-notify>' /etc/apache2/conf-available/sls-mass-notify.conf \
    && grep -Fq '<Directory /var/www/html/sls_mass_notify>' /etc/apache2/conf-available/sls-mass-notify.conf || {
      log "The SLS Apache integration is missing or is not enabled."
      exit 1
    }
  notify_module_ready=0
  for _attempt in $(seq 1 20); do
    if asterisk_module_loaded res_pjsip_notify.so; then
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
  runtime_python_files=(
    /usr/local/bin/sls_mass_notify/sls_notify.py
    /usr/local/bin/sls_mass_notify/sls_config.py
    /usr/local/bin/sls_mass_notify/sls_branded_email.py
    /usr/local/bin/sls_mass_notify/sls_branded_discord.py
    /usr/local/bin/sls_mass_notify/sls_notification_destinations.py
    /usr/local/bin/sls_mass_notify/sls_system_notifications.py
    /usr/local/bin/sls_mass_notify/sls_nws_status.py
    /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py
  )
  for runtime_python in "${runtime_python_files[@]}"; do
    [ -x "$runtime_python" ] || {
      log "Required Python runtime is missing or not executable: $runtime_python"
      exit 1
    }
  done
  python3 - "${runtime_python_files[@]}" <<'PY'
import pathlib
import sys

for value in sys.argv[1:]:
    path = pathlib.Path(value)
    compile(path.read_text(encoding="utf-8"), str(path), "exec")
PY
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
  media_probe="/var/www/html/sls_mass_notify/installer-render-check-$$.png"
  media_fetch="/tmp/sls-mass-notify-render-fetch-$$.png"
  rm -f "$media_probe" "$media_fetch"
  if ! runuser -u asterisk -- convert -size 480x272 xc:'#991b1b' -font DejaVu-Sans-Bold \
    -fill white -gravity center -pointsize 24 -annotate +0+0 'SLS render test' \
    -colorspace sRGB -depth 8 -interlace none -strip "PNG24:$media_probe"; then
    rm -f "$media_probe" "$media_fetch"
    log "The asterisk service account could not render an alert image in the public media directory."
    exit 1
  fi
  /usr/sbin/apache2ctl configtest >>"$LOG_FILE" 2>&1 || {
    rm -f "$media_probe" "$media_fetch"
    log "Apache configuration validation failed after integration was installed. See $LOG_FILE."
    exit 1
  }
  media_code="$(local_web_probe "/sls_mass_notify/$(basename "$media_probe")" "$media_fetch" '^(200)$' || true)"
  if [ "$media_code" != "200" ] || ! cmp -s "$media_probe" "$media_fetch"; then
    rm -f "$media_probe" "$media_fetch"
    log "Public phone-image delivery check failed with HTTP $media_code."
    exit 1
  fi
  media_identity="$(identify -format '%w %h %[bit-depth] %[colorspace] %[interlace]' "$media_fetch" 2>/dev/null || true)"
  rm -f "$media_probe" "$media_fetch"
  case "$media_identity" in
    "480 272 8 sRGB None"|"480 272 8 RGB None") ;;
    *)
      log "Rendered phone image does not meet the 480x272, 8-bit, non-interlaced requirement: $media_identity"
      exit 1
      ;;
  esac
  log "Verifying Asterisk PJSIP contact inventory and SLS AMI authentication."
  if ! asterisk -rx "pjsip show contacts" >/tmp/sls-mass-notify-pjsip-contacts.out 2>&1; then
    log "Asterisk could not return the PJSIP contact inventory. See /tmp/sls-mass-notify-pjsip-contacts.out."
    exit 1
  fi
  if ! verify_ami_with_repair; then
    exit 1
  fi
  notify_capabilities="$(mktemp /tmp/sls-mass-notify-notify-capabilities.XXXXXX)"
  if ! /usr/bin/timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --notify-capabilities-json >"$notify_capabilities"; then
    rm -f "$notify_capabilities"
    log "SLS could not inspect Asterisk SIP NOTIFY routing capabilities."
    exit 1
  fi
  notify_routing_mode="$(python3 - "$notify_capabilities" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    capabilities = json.load(handle)
if not isinstance(capabilities, dict) or capabilities.get("endpoint_target") is not True:
    raise SystemExit("Asterisk does not expose portable endpoint-targeted PJSIPNotify")
mode = capabilities.get("routing_mode")
if mode not in {"endpoint_fanout", "contact_uri"}:
    raise SystemExit("Asterisk returned an invalid SIP NOTIFY routing mode")
print(mode)
PY
)" || {
    cat "$notify_capabilities" >>"$LOG_FILE" 2>/dev/null || true
    rm -f "$notify_capabilities"
    log "Asterisk does not expose the required endpoint-targeted PJSIPNotify capability."
    exit 1
  }
  if [ "$notify_routing_mode" = "contact_uri" ]; then
    log "SIP NOTIFY routing verified: endpoint fan-out is primary; contact URI routing is available for mixed-format registrations."
  else
    log "SIP NOTIFY routing verified: portable endpoint fan-out will be used because no usable default outbound endpoint is configured."
  fi
  rm -f "$notify_capabilities"
  # Always exercise the same AMI discovery path used by the Dashboard. Do not
  # infer whether it should run from the presentation-dependent summary line of
  # `pjsip show contacts`; community Asterisk builds format that output
  # differently, and an empty PBX is a valid installation state.
  endpoint_inventory="$(mktemp /tmp/sls-mass-notify-endpoints.XXXXXX)"
  endpoint_inventory_err="$(mktemp /tmp/sls-mass-notify-endpoints-error.XXXXXX)"
  if ! /usr/bin/timeout 15 python3 /usr/local/bin/sls_mass_notify/sls_notify.py --list-endpoints-json \
    >"$endpoint_inventory" 2>"$endpoint_inventory_err"; then
    cat "$endpoint_inventory_err" >>"$LOG_FILE" 2>/dev/null || true
    rm -f "$endpoint_inventory" "$endpoint_inventory_err"
    log "SLS AMI PJSIP contact discovery failed. Confirm the loopback manager user has system-event read permission; see $LOG_FILE."
    exit 1
  fi
  endpoint_records=""
  if ! endpoint_records="$(endpoint_inventory_records "$endpoint_inventory" 2>>"$LOG_FILE")"; then
    cat "$endpoint_inventory" >>"$LOG_FILE" 2>/dev/null || true
    rm -f "$endpoint_inventory" "$endpoint_inventory_err"
    log "SLS AMI PJSIP contact discovery returned invalid data; see $LOG_FILE."
    exit 1
  fi

  registered_endpoint_count=0
  yealink_endpoint_list=""
  while IFS=$'\t' read -r extension phone_format contact_count unknown_format mixed_format formats_csv user_agent; do
    [ -n "$extension" ] || continue
    registered_endpoint_count=$((registered_endpoint_count + 1))
    if [ "$unknown_format" = "1" ]; then
      log "Warning: registered endpoint $extension has an unknown phone format (user agent: ${user_agent:-not reported})."
      log "A safe generic SIP NOTIFY fallback will be used; add a Phone Format Override after confirming the device family."
    fi
    case ",${formats_csv}," in
      *,yealink,*|*,yealink_text,*)
        yealink_endpoint_list="${yealink_endpoint_list}${yealink_endpoint_list:+,}${extension}"
        ;;
    esac
    if ! mixed_endpoint_visual_route_supported "$mixed_format" "$notify_routing_mode"; then
      rm -f "$endpoint_inventory" "$endpoint_inventory_err"
      log "Registered extension $extension has mixed phone formats ($formats_csv), but Asterisk has no usable default_outbound_endpoint for contact-URI SIP NOTIFY."
      log "Installation is stopping before commit because visual delivery would be known-broken for that extension. Configure a valid PBX-managed default outbound endpoint, use one phone family per extension, or apply an explicit format override and rerun the installer."
      exit 1
    fi
    if [ "$mixed_format" = "1" ]; then
      log "Warning: extension $extension has mixed registered phone formats ($formats_csv). Distinct contact-URI SIP NOTIFY submission is available; runtime delivery remains fail-closed if any registration URI cannot be resolved."
    fi
    if ! verify_registered_endpoint_route "$extension"; then
      rm -f "$endpoint_inventory" "$endpoint_inventory_err"
      log "Registered endpoint route validation failed before the installation was committed."
      exit 1
    fi
    log "Registered endpoint $extension discovery verified through AMI: format=$phone_format contacts=$contact_count."
  done <<<"$endpoint_records"

  if [ -n "$yealink_endpoint_list" ]; then
    log "Warning: Yealink registration(s) detected on extension(s) $yealink_endpoint_list. Auto-answer requires the phone's Intercom Allow policy; XML SIP NOTIFY display requires push_xml.sip_notify = 1 (Features > Remote Control > SIP Notify)."
    log "The installer does not modify handset provisioning, firmware, or SIP peers. Confirm these settings on each Yealink phone or in its authorized provisioning template."
  fi

  if [ "$registered_endpoint_count" -eq 0 ]; then
    log "No registered numeric PJSIP contacts are present; AMI discovery and empty-PBX handling were verified."
  else
    log "AMI discovery and PJSIP paging routes verified for $registered_endpoint_count registered endpoint(s)."
  fi
  rm -f "$endpoint_inventory" "$endpoint_inventory_err"
  php -l /var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php >/dev/null
  code="$(local_web_probe /api/sipnotify/desktop /tmp/sls-sipnotify-api.out '^(401|429)$' || true)"
  case "$code" in
    401|429) ;;
    *)
      log "Desktop notification API smoke test expected HTTP 401/429 for /api/sipnotify/desktop, got $code. See /tmp/sls-sipnotify-api.out."
      exit 1
      ;;
  esac
  stream_code="$(local_web_probe /api/sipnotify/desktop/stream /tmp/sls-sipnotify-stream-api.out '^(401|429)$' || true)"
  case "$stream_code" in
    401|429) ;;
    *)
      log "Desktop live-stream API expected HTTP 401/429 without credentials, got $stream_code. See /tmp/sls-sipnotify-stream-api.out."
      exit 1
      ;;
  esac
  verify_control_api_authentication || exit 1
  # Use the canonical directory URL. Apache otherwise returns its expected
  # DirectorySlash 301 before the protected API front controller runs.
  control_code="$(local_web_probe /api/sls-mass-notify/ /tmp/sls-control-api.out '^(401|403|405|429)$' || true)"
  case "$control_code" in
    401|403|405|429) ;;
    *)
      log "Control API route smoke test expected HTTP 401/403/405/429 for /api/sls-mass-notify/, got $control_code. See /tmp/sls-control-api.out."
      exit 1
      ;;
  esac
  verify_desktop_sse_handshake || exit 1
  [ "$(stat -c '%U:%G' /usr/local/bin/sls_mass_notify)" = "root:root" ] || {
    log "Executable runtime is not owned by root:root."
    exit 1
  }
  [ "$(stat -c '%U:%G' /usr/local/bin/sls_mass_notify/piper/venv/bin/piper)" = "root:root" ] || {
    log "Piper executable is not owned by root:root."
    exit 1
  }
  systemctl is-active --quiet cron || {
    log "The cron scheduler stopped during installation."
    exit 1
  }
  root_cron="$(crontab -l 2>/dev/null || true)"
  asterisk_cron="$(crontab -u asterisk -l 2>/dev/null || true)"
  update_count="$(printf '%s\n' "$root_cron" | grep -Fc '/usr/local/bin/sls_mass_notify/sls_mass_notify_update.sh' || true)"
  maintenance_count="$(printf '%s\n' "$root_cron" | grep -Fc '/usr/local/bin/sls_mass_notify/sls_mass_notify_maintenance.sh' || true)"
  weather_count="$(printf '%s\n' "$asterisk_cron" | grep -Fc '/usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh' || true)"
  weather_canonical_count="$(printf '%s\n' "$asterisk_cron" | grep -Fxc '* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh' || true)"
  schedule_count="$(printf '%s\n' "$asterisk_cron" | grep -Fc '/usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php' || true)"
  schedule_canonical_count="$(printf '%s\n' "$asterisk_cron" | grep -Fxc '* * * * * /usr/bin/timeout 1200 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php' || true)"
  if [ "$update_count" -ne 1 ]; then
    log "Expected exactly one root automatic-update cron entry; found $update_count."
    exit 1
  fi
  if [ "$maintenance_count" -ne 1 ]; then
    log "Expected exactly one root maintenance cron entry; found $maintenance_count."
    exit 1
  fi
  if [ "$weather_count" -ne 1 ] || [ "$weather_canonical_count" -ne 1 ]; then
    log "Expected exactly one canonical Asterisk weather scheduler cron entry; found $weather_count total and $weather_canonical_count canonical."
    exit 1
  fi
  if [ "$schedule_count" -ne 1 ] || [ "$schedule_canonical_count" -ne 1 ]; then
    log "Expected exactly one canonical Asterisk announcement scheduler cron entry; found $schedule_count total and $schedule_canonical_count canonical."
    exit 1
  fi
  if ! runuser -u asterisk -- /usr/bin/timeout 20 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php --self-test >>"$LOG_FILE" 2>&1; then
    log "Scheduled-announcement worker failed its Asterisk-account self-test."
    exit 1
  fi
  scheduler_probe="$(mktemp -d /tmp/sls-mass-notify-scheduler-check.XXXXXX)"
  chown asterisk:asterisk "$scheduler_probe"
  chmod 0750 "$scheduler_probe"
  printf '%s\n' '{"enabled":"0","xweather":{"enabled":"0"}}' >"$scheduler_probe/disabled.config"
  chown asterisk:asterisk "$scheduler_probe/disabled.config"
  chmod 0640 "$scheduler_probe/disabled.config"
  if ! runuser -u asterisk -- /usr/bin/env \
    CONFIG_FILE="$scheduler_probe/disabled.config" \
    DATA_DIR="$scheduler_probe" \
    LOG="$scheduler_probe/weather.log" \
    RUNTIME_DIR="/usr/local/bin/sls_mass_notify" \
    /usr/bin/timeout 15 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh; then
    rm -rf "$scheduler_probe"
    log "Weather scheduler wrapper failed its disabled-config execution check."
    exit 1
  fi
  rm -rf "$scheduler_probe"
  if [ ! -x /usr/sbin/sendmail ]; then
    log "Warning: /usr/sbin/sendmail is unavailable; email delivery will remain disabled until a local MTA is installed."
  fi
  ls -lh /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices
}

main() {
  cd /tmp
  : >"$LOG_FILE"
  trap guard_config_on_exit EXIT
  set_install_stage "platform validation" "Confirm this is a healthy FreePBX 17 host with working database, Asterisk, fwconsole, package repositories, and local AMI access, then rerun the installer."
  require_freepbx
  acquire_maintenance_coordination
  set_install_stage "dependency installation" "Restore Debian package repository access and install the prerequisite named in the installer log, then rerun the installer."
  install_dependencies
  preflight_platform
  preflight_python
  ensure_freepbx_prerequisites
  set_install_stage "release download and validation" "Confirm the release URL and checksum, restore GitHub or network access if needed, and rerun the installer."
  download_tgz
  verify_tgz
  acquire_settings_coordination
  snapshot_config
  validate_preserved_config_prerequisites
  set_install_stage "module activation" "Run fwconsole ma list and inspect the installer log for the rejected module or signature check, correct that condition, then rerun the installer."
  stage_module_directory
  activate_staged_module
  ensure_local_signer
  if ! SLS_MASS_NOTIFY_DEFER_SIGNING=1 fwconsole ma install "$MODULE" >>"$LOG_FILE" 2>&1; then
    if ! module_registered_at_expected_version; then
		log "FreePBX rejected the module installation before registering version 0.1.0. See $LOG_FILE."
      exit 1
    fi
	log "FreePBX registered version 0.1.0 but reported a nonfatal install status; runtime verification will continue."
  fi
  fwconsole ma enable "$MODULE" >>"$LOG_FILE" 2>&1 || true
  SLS_MASS_NOTIFY_MODULE="$MODULE" sync_module_version
  set_install_stage "runtime integration" "Use General Settings > Danger Zone > Repair Installation after correcting the reported Asterisk, Apache, cron, signer, or filesystem prerequisite."
  ensure_runtime_installed
  asterisk -rx "module reload res_pjsip_notify.so" >>"$LOG_FILE" 2>&1 || true
  ensure_piper_runtime
  /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh || {
    log "Packaged Piper voice installation failed. See $LOG_FILE."
    exit 1
  }
  repair_runtime_permissions
  fwconsole chown
  secure_central_config
  repair_runtime_permissions
  fwconsole reload
  asterisk -rx "module reload res_pjsip_notify.so" >>"$LOG_FILE" 2>&1 || true
  repair_runtime_permissions
  fwconsole reload
  repair_runtime_permissions
  asterisk -rx "dialplan reload" || true
  set_install_stage "post-install verification" "Review the failed verification in /tmp/slsmassnotifyserver-install.log, correct that PBX-specific prerequisite, and rerun the installer or Repair Installation."
  verify_piper_voices
  verify_install
  verify_config_unchanged
  INSTALL_COMMITTED=1
  clear_install_failure
  log "SLS Mass Notify install finished."
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
