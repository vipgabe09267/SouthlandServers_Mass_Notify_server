#!/usr/bin/env bash
set -euo pipefail

umask 027

INSTALLER_SOURCE_PATH="$(readlink -f -- "${BASH_SOURCE[0]}")"

MODULE="slsmassnotifyserver"
if [ -n "${SLS_MASS_NOTIFY_MODULE:-}" ] \
  && [ "${SLS_MASS_NOTIFY_MODULE}" != "$MODULE" ]; then
  printf '%s\n' \
    "SLS_MASS_NOTIFY_MODULE is fixed to the FreePBX raw module name '$MODULE'; refusing an alternate value." >&2
  exit 2
fi
TGZ="${SLS_MASS_NOTIFY_TGZ:-/tmp/slsmassnotifyserver-0.1.5-beta.tgz}"
URL="${SLS_MASS_NOTIFY_TGZ_URL:-${1:-}}"
SHA256="${SLS_MASS_NOTIFY_SHA256:-}"
TOKEN="${SLS_MASS_NOTIFY_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}"
LOG_FILE="${SLS_MASS_NOTIFY_INSTALL_LOG:-/tmp/slsmassnotifyserver-install.log}"
EXPECTED_TGZ_SHA256="1427801ed3f9427241a8f8db3ce6f67d1dcc4189e4ebc9e5d98bdb3cf03eb693"
DATA_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin"
CONFIG_FILE="$DATA_DIR/mass-notifications.config"
CONFIG_SNAPSHOT=""
CONFIG_HASH_BEFORE=""
FRESH_CONFIG_INITIALIZED=0
FRESH_PHONE_CAPACITY=25
PENDING_CONFIG_STATE=""
PENDING_CONFIG_PATH=""
PENDING_CONFIG_SNAPSHOT=""
PENDING_CONFIG_HASH=""
CONFIG_LOCK_FD=""
SETTINGS_LOCK="$DATA_DIR/mass-notifications.config.lock"
STAGING_DIR=""
DOWNLOAD_DIR=""
MODULE_BACKUP_DIR=""
ROLLBACK_FAILED=0
ROLLBACK_ATTEMPTED=0
MODULE_ACTIVATED=0
INSTALL_COMMITTED=0
HAD_EXISTING_MODULE=0
MODULE_WAS_ENABLED=0
APT_METADATA_REFRESHED=0
ASTERISK_PACKAGE_REPAIR_ATTEMPTS=""
REPAIR_ASTERISK_PACKAGES="${SLS_MASS_NOTIFY_REPAIR_ASTERISK_PACKAGES:-1}"
INSTALL_MAINTENANCE_LOCK_FD=""
FREEPBX_CONFIRMED=0
REQUESTED_SYSTEM_TIMEZONE="${SLS_MASS_NOTIFY_TIMEZONE:-}"
ORIGINAL_SYSTEM_TIMEZONE=""
SYSTEM_TIMEZONE_CHANGED=0
CONFIRMED_SYSTEM_TIMEZONE=""
# Regression tests source this installer so they can exercise its protected
# filesystem helpers.  Keep their fixture failure markers from creating or
# deleting real FreePBX Dashboard notifications on the host running the test.
INSTALL_NOTIFICATION_SIDE_EFFECTS=1
INSTALL_STAGE="initialization"
INSTALL_SOLUTION="Review /tmp/slsmassnotifyserver-install.log, correct the reported prerequisite, then run the same installer again."
INSTALL_FAILURE_FILE="$DATA_DIR/install-failure.json"
INSTALL_ERROR_CATEGORY="install_command_failed"
INSTALL_EXIT_CODE=1
PRIVILEGED_INSTALL_BOOTSTRAP=""
TRUST_TOOL=""
TRUST_ROOT="/var/lib/sls-mass-notify-trust"
INSTALL_BOOTSTRAP_DIR=""
OFFLINE_ASSET_DIR="$(dirname "$TGZ")"
WORKER_GUARD_TOOL=""
IDLE_TOOL=""
RECOVERY_TOOL=""
RECOVERY_DIR=""
STATIC_MUTATION_STARTED=0
UPGRADE_TRUST_DIR=""

# PHP/FreePBX code is maintained by the web account. It must never become a
# root program merely because this installer is root. Privileged operations
# use the authenticated fixed-operation helper below, not a PHP module hook.
sls_as_asterisk() {
  local uid
  uid="$(id -u asterisk 2>/dev/null)" || { log 'The asterisk service account is missing.'; return 1; }
  [[ "$uid" =~ ^[0-9]+$ ]] && [ "$uid" -ne 0 ] || { log 'The asterisk service account must have a nonzero UID.'; return 1; }
  run_without_install_maintenance_lock /usr/sbin/runuser -u asterisk -- "$@"
}

php() { sls_as_asterisk /usr/bin/php "$@"; }

fwconsole() {
  case "${1:-}" in
    chown|start|stop|restart)
      log "Root-dependent fwconsole action '$1' is not permitted inside this installer. Use the fixed protected service/permission phases."
      return 1 ;;
  esac
  sls_as_asterisk /usr/sbin/fwconsole "$@"
}

protected_install_phase() {
  [ -n "$PRIVILEGED_INSTALL_BOOTSTRAP" ] && [ -f "$PRIVILEGED_INSTALL_BOOTSTRAP" ] || {
    log 'The authenticated privileged installer has not been prepared. No root module hook will be used as a fallback.'
    return 1
  }
  /usr/bin/python3 -I "$PRIVILEGED_INSTALL_BOOTSTRAP" --trust-root "$TRUST_ROOT" "$@"
}

initialize_configuration_keyring() {
  /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_config_crypto.py" init >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

prepare_installer_bootstrap() {
  local module_name
  INSTALL_BOOTSTRAP_DIR="$(mktemp -d /tmp/sls-mass-notify-bootstrap.XXXXXX)" || return 1
  chmod 0700 "$INSTALL_BOOTSTRAP_DIR" || return 1
  for module_name in sls_module_trust.py sls_release_trust.py sls_privileged_install.py sls_installer_recovery.py sls_install_idle.py sls_install_guard.py sls_audio_state.py sls_config_crypto.py sls_upgrade_trust.py; do
    install -o root -g root -m 0700 "$STAGING_DIR/$MODULE/bin/sls_mass_notify/$module_name" "$INSTALL_BOOTSTRAP_DIR/$module_name" || return 1
  done
  install -o root -g root -m 0600 "$STAGING_DIR/$MODULE/bin/sls_mass_notify/freepbx-mirror-signing.pub" "$INSTALL_BOOTSTRAP_DIR/freepbx-mirror-signing.pub" || return 1
  export SLS_CONFIG_CRYPTO_PHP="$STAGING_DIR/$MODULE/api/sls-mass-notify/config-crypto.php"
  initialize_configuration_keyring || return 1
  WORKER_GUARD_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_guard.py"
  IDLE_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_install_idle.py"
  RECOVERY_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_installer_recovery.py"
  TRUST_TOOL="$INSTALL_BOOTSTRAP_DIR/sls_module_trust.py"
  PRIVILEGED_INSTALL_BOOTSTRAP="$INSTALL_BOOTSTRAP_DIR/sls_privileged_install.py"
  # Bootstrap helpers and the protected encryption keyring are prepared.
  # Public code, user settings, trust pointers and root schedules are unchanged.
}

prepare_authenticated_installer() {
  local manifest signature module_name inventory digest
  [ -n "$PRIVILEGED_INSTALL_BOOTSTRAP" ] && [ -f "$PRIVILEGED_INSTALL_BOOTSTRAP" ] || return 1
  manifest="$DOWNLOAD_DIR/release-manifest.json"
  signature="$DOWNLOAD_DIR/release-manifest.sig"
  [ -f "$manifest" ] && [ ! -L "$manifest" ] && [ -f "$signature" ] && [ ! -L "$signature" ] || {
    log 'The release manifest and Ed25519 signature are required for protected installation, including offline candidates. Supply SLS_MASS_NOTIFY_MANIFEST and SLS_MASS_NOTIFY_MANIFEST_SIGNATURE alongside the archive.'
    return 1
  }
  UPGRADE_TRUST_DIR="$(mktemp -d /var/lib/sls-mass-notify-upgrade-review.XXXXXX)" || return 1
  chmod 0700 "$UPGRADE_TRUST_DIR" || return 1
  log "Upgrade trust and review evidence: $UPGRADE_TRUST_DIR"
  /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" \
    --workspace "$UPGRADE_TRUST_DIR" --trust-root "$TRUST_ROOT" \
    --archive "$TGZ" --manifest "$manifest" --signature "$signature" || return 1
  # This only loads already approved protected inventories. It does not learn
  # expected bytes from the installed Dashboard or Framework tree.
  /usr/bin/python3 -I - "$TRUST_TOOL" "$TRUST_ROOT" "$STAGING_DIR/$MODULE" "$UPGRADE_TRUST_DIR/approvals.json" <<'PY' || return 1
import importlib.util
from pathlib import Path
import sys
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('sls_installer_trust', sys.argv[1])
trust = importlib.util.module_from_spec(spec)
spec.loader.exec_module(trust)
# Inventory every stock module before prepare/dependencies. Only the two
# publisher-authenticated Dashboard overlays and the explicitly reviewed menu
# transition may differ; no live hashes become new approval evidence.
import os, stat
candidate = Path(sys.argv[3])
web = Path('/var/www/html')
import json
approvals = json.loads(trust.read(Path(sys.argv[4]), trust.MAX_MANIFEST * 4, protected=True))
prior = approvals['prior']
for module in ('dashboard', 'framework'):
    data = approvals['approved'][module]
    overlays = {
        'sections/SlsMassNotifyAnnouncement.class.php': candidate / 'dashboard/sections/SlsMassNotifyAnnouncement.class.php',
        'views/sections/sls-mass-notify-announcement.php': candidate / 'dashboard/views/sections/sls-mass-notify-announcement.php',
    } if module == 'dashboard' else {}
    for name, path in overlays.items():
        if name not in data['files'] or trust.digest(trust.read(path, trust.MAX_ARCHIVE)) != data['files'][name]['sha256']:
            raise SystemExit('Reviewed Dashboard integration does not match this signed candidate: ' + name)
    actual = trust.inventory_tree(web / 'admin/modules' / module, module)
    expected = {name for name, entry in data['files'].items() if entry['target'].startswith('admin/modules/' + module + '/')}
    if actual - set(overlays) != expected - set(overlays):
        raise SystemExit('Installed ' + module + ' file inventory is not covered by independent approval')
    total = 0
    for name, entry in data['files'].items():
        if name in overlays:
            if name not in actual: continue
            observed = trust.digest(trust.read(web / entry['target'], trust.MAX_ARCHIVE))
            previous = prior.get(module, {}).get('files', {}).get(name, {}).get('sha256')
            if observed not in {entry['sha256'], previous}:
                raise SystemExit('Existing SLS Dashboard overlay lacks prior approval: ' + name)
            continue
        if 'link_target' in entry:
            path = web / entry['target']
            parent = trust.directory(path.parent)
            try:
                before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                target = str(web / entry['link_target'])
                if not stat.S_ISLNK(before.st_mode) or before.st_nlink != 1 or os.readlink(path.name, dir_fd=parent) != target:
                    raise SystemExit('Reviewed Framework asset link changed')
                body = trust.read(Path(target), trust.MAX_ARCHIVE)
                after = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                if (before.st_dev, before.st_ino, before.st_ctime_ns) != (after.st_dev, after.st_ino, after.st_ctime_ns):
                    raise SystemExit('Reviewed Framework asset link changed during inspection')
            finally: os.close(parent)
        else:
            body = trust.read(web / entry['target'], trust.MAX_ARCHIVE)
        total += len(body)
        if total > 1024 * 1024 * 1024: raise SystemExit('Stock inventory exceeds verification size bound')
        allowed = {entry['sha256']}
        if module == 'framework' and name == 'amp_conf/htdocs/admin/views/menu_items.php':
            previous = data.get('uninstall', {}).get('replace', {}).get(name)
            if previous: allowed.add(previous)
            previous_overlay = prior.get(module, {}).get('files', {}).get(name, {}).get('sha256')
            if previous_overlay: allowed.add(previous_overlay)
        if trust.digest(body) not in allowed:
            raise SystemExit('Stock file differs from independently approved inventory: ' + module + '/' + name)
PY
  # Validate legacy state before trust pointers, runtime or maintenance change.
  /usr/bin/python3 -I "$PRIVILEGED_INSTALL_BOOTSTRAP" --trust-root "$UPGRADE_TRUST_DIR/registry" preflight || return 1
  if [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" = 1 ]; then
    log "Inventory preflight passed. Review $UPGRADE_TRUST_DIR; no module, trust pointers or maintenance schedules were changed."
    return 0
  fi
  prepare_install_recovery || return 1
  STATIC_MUTATION_STARTED=1
  /usr/bin/python3 -I "$TRUST_TOOL" --root "$TRUST_ROOT" enroll-sls \
    --archive "$TGZ" --manifest "$manifest" --signature "$signature" || return 1
  for module_name in dashboard framework; do
    inventory="$UPGRADE_TRUST_DIR/$module_name.json"
    digest="$(sha256sum "$inventory" | cut -d ' ' -f 1)" || return 1
    /usr/bin/python3 -I "$TRUST_TOOL" --root "$TRUST_ROOT" enroll-reviewed --inventory "$inventory" --sha256 "$digest" || return 1
  done
  protected_install_phase plan
}

# The protected log opener requires Python before the ordinary dependency pass.
# Bootstrap only that missing package on an identified Debian 12 FreePBX host;
# no log pathname is opened or written until the safe opener can run.
ensure_installer_log_prerequisites() {
  local python_probe='import os, pwd, stat, sys; assert all(hasattr(os, name) for name in ("O_NOFOLLOW", "O_DIRECTORY", "O_CLOEXEC", "O_NONBLOCK")); assert callable(os.fstat)'
  local platform_id="" platform_version="" platform_key platform_value
  if [ -x /usr/bin/python3 ]; then
    if /usr/bin/python3 -I -c "$python_probe" >/dev/null 2>&1; then
      return 0
    fi
    printf 'The existing /usr/bin/python3 cannot load the standard library required for secure installer logging. Repair the system Python installation, then retry; no binary was replaced.\n' >&2
    return 1
  fi
  if [ -e /usr/bin/python3 ] || [ -L /usr/bin/python3 ]; then
    printf '/usr/bin/python3 exists but is not executable or its link is broken. Repair the system Python installation, then retry; no binary was replaced.\n' >&2
    return 1
  fi
  if [ -r /etc/os-release ]; then
    while IFS='=' read -r platform_key platform_value; do
      platform_value="${platform_value#\"}"
      platform_value="${platform_value%\"}"
      platform_value="${platform_value#\'}"
      platform_value="${platform_value%\'}"
      case "$platform_key" in
        ID) platform_id="$platform_value" ;;
        VERSION_ID) platform_version="$platform_value" ;;
      esac
    done </etc/os-release
  fi
  if [ "$platform_id" != "debian" ] || [ "$platform_version" != "12" ] \
    || [ ! -x /usr/sbin/fwconsole ] || [ ! -x /usr/sbin/asterisk ] \
    || [ ! -r /etc/freepbx.conf ] || [ ! -d /var/www/html/admin/modules ] \
    || [ ! -x /usr/bin/apt-get ]; then
    printf 'Python 3 is required for secure installer logging. Automatic bootstrap requires a recognized Debian 12 FreePBX host and apt-get; no packages were changed.\n' >&2
    return 1
  fi
  printf 'Installing missing Python 3 for secure installer logging. Package-manager output follows on this console.\n'
  if ! DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get update; then
    printf 'Unable to refresh Debian package metadata for the missing Python prerequisite. Correct repository access, then retry.\n' >&2
    return 1
  fi
  APT_METADATA_REFRESHED=1
  if ! DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install -y --no-install-recommends --no-remove python3; then
    printf 'Unable to install the missing Python prerequisite. Review the package-manager error above, then retry.\n' >&2
    return 1
  fi
  if [ ! -x /usr/bin/python3 ] || ! /usr/bin/python3 -I -c "$python_probe" >/dev/null 2>&1; then
    printf 'Python installation returned but its executable or required standard library is unavailable. Repair the system Python installation before retrying.\n' >&2
    return 1
  fi
}

# Open only a root-owned regular file through trusted directories. Creation is
# exclusive; an existing file is never truncated or written before fd identity
# verification. Sticky root-owned /tmp and /run/lock are permitted. Only log
# callers may adopt a legacy asterisk-owned regular file; locks remain strict.
open_root_owned_file() {
  local output_variable="$1" protected_path="$2" file_purpose="${3:-lock}"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 -I - "$protected_path" "$file_purpose" <<'PY'
import os
import pwd
import stat
import sys

path = sys.argv[1]
purpose = sys.argv[2]
if purpose not in {"lock", "log"}:
    raise SystemExit("Unknown protected file purpose.")
parts = path.split("/")[1:]
if not path.startswith("/") or not parts or any(part in {"", ".", ".."} for part in parts):
    raise SystemExit(1)
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW
parent = os.open("/", directory_flags)
try:
    for component in parts[:-1]:
        child = os.open(component, directory_flags, dir_fd=parent)
        os.close(parent)
        parent = child
        metadata = os.fstat(parent)
        if metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX):
            raise SystemExit(1)
    flags = os.O_WRONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    try:
        descriptor = os.open(parts[-1], flags | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=parent)
    except FileExistsError:
        descriptor = os.open(parts[-1], flags, dir_fd=parent)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise SystemExit("Refusing a linked or non-regular protected file.")
        if purpose == "log":
            allowed_owners = {0}
            try:
                allowed_owners.add(pwd.getpwnam("asterisk").pw_uid)
            except KeyError:
                pass
            if metadata.st_uid not in allowed_owners:
                raise SystemExit("Protected logs must be owned by root or the Asterisk service account.")
            # Old installers/FreePBX chown can leave service-owned logs in
            # sticky /tmp. O_CREAT would fail even as root on Debian. The
            # existing file was opened without it; secure this exact inode
            # before Bash reopens it, without following or replacing links.
            os.fchown(descriptor, 0, 0)
            os.fchmod(descriptor, 0o600)
        elif metadata.st_uid != 0 or metadata.st_mode & 0o022:
            raise SystemExit(1)
        current = os.fstat(descriptor)
        entry = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
        if (current.st_dev, current.st_ino) != (entry.st_dev, entry.st_ino) or current.st_nlink != 1 or current.st_uid != 0 or current.st_mode & 0o022:
            raise SystemExit("Protected file changed while it was being opened.")
        print(f"{metadata.st_dev}:{metadata.st_ino}")
    finally:
        os.close(descriptor)
finally:
    os.close(parent)
PY
)" || return 1
  exec {protected_fd}>>"$protected_path" || return 1
  opened_identity="$(stat -Lc '%d:%i' "/proc/${BASHPID}/fd/$protected_fd" 2>/dev/null)" || opened_identity=""
  if [ "$opened_identity" != "$protected_identity" ]; then
    exec {protected_fd}>&-
    return 1
  fi
  chmod 0600 "/proc/${BASHPID}/fd/$protected_fd" || {
    exec {protected_fd}>&-
    return 1
  }
  printf -v "$output_variable" '%s' "$protected_fd"
}

log() {
  printf '%s\n' "$*"
}

set_install_stage() {
  INSTALL_STAGE="$1"
  INSTALL_SOLUTION="$2"
}

ensure_data_directory() {
  DATA_DIRECTORY_PATH="$DATA_DIR" /usr/bin/python3 -I - <<'PY'
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
    "log" => substr(preg_replace("/[[:cntrl:]]/", "", (string)$argv[6]), 0, 500),
    "error_category" => preg_match("/^[a-z][a-z0-9_]{0,63}$/", $argv[4]) ? $argv[4] : "install_command_failed",
    "exit_code" => max(1, min(255, (int)$argv[5])),
];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($argv[3], $json . PHP_EOL, LOCK_EX) === false) {
    exit(1);
}
' "$INSTALL_STAGE" "$INSTALL_SOLUTION" "$marker_tmp" "$INSTALL_ERROR_CATEGORY" "$INSTALL_EXIT_CODE" "$LOG_FILE" 2>/dev/null; then
    rm -f "$marker_tmp"
    return 0
  fi
  safe_config_restore "$marker_tmp" "$INSTALL_FAILURE_FILE" 2>/dev/null || {
    rm -f "$marker_tmp"
    return 0
  }
  rm -f "$marker_tmp"
  [ "${INSTALL_NOTIFICATION_SIDE_EFFECTS:-1}" -eq 1 ] || return 0
  php -r '
require "/etc/freepbx.conf";
$detail = "Stage: " . $argv[1] . ". " . $argv[2] . " Installer log: " . htmlspecialchars($argv[3], ENT_QUOTES, "UTF-8");
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
' "$INSTALL_STAGE" "$INSTALL_SOLUTION" "$LOG_FILE" >/dev/null 2>&1 || true
}

clear_install_failure() {
  if [ ! -L "$INSTALL_FAILURE_FILE" ]; then
    rm -f "$INSTALL_FAILURE_FILE" 2>/dev/null || true
  fi
  [ "${INSTALL_NOTIFICATION_SIDE_EFFECTS:-1}" -eq 1 ] || return 0
  php -r '
require "/etc/freepbx.conf";
\FreePBX::Notifications()->delete("slsmassnotifyserver", "INSTALLFAILED");
exit(0);
' >/dev/null 2>&1 || true
}

local_web_probe() {
  local path="$1" output="$2" expected_pattern="$3"
  local code target_url target_resolve targets probe_deadline remaining
  probe_deadline=$((SECONDS + 25))
  # Read Apache's parsed vhosts, then connect only to loopback. Public ports,
  # DNS, firewall rules and Apache configuration are never changed by a probe.
  targets="$(php -r '
$helper = "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
if (!is_file($helper)) { exit(0); }
require_once $helper;
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents(
    "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config"
));
foreach (\FreePBX\modules\SlsLocalWebProbe::targets((string)($settings["public_pbx_host"] ?? "")) as $target) {
    echo $target["url"], "\t", $target["resolve"], "\n";
}
' 2>/dev/null || true)"
  if [ -z "$targets" ]; then
    # Previous releases restored by rollback may not contain the new helper.
    targets=$'http://127.0.0.1:80\t127.0.0.1:80:127.0.0.1\nhttps://127.0.0.1:443\t127.0.0.1:443:127.0.0.1'
  fi
  while IFS=$'\t' read -r target_url target_resolve; do
    [ -n "$target_url" ] && [ -n "$target_resolve" ] || continue
    remaining=$((probe_deadline - SECONDS))
    [ "$remaining" -gt 0 ] || break
    [ "$remaining" -le 20 ] || remaining=20
    code="$(curl -ksS --noproxy '*' --connect-timeout 5 --max-time "$remaining" --max-filesize 262144 \
      -o "$output" -w '%{http_code}' --resolve "$target_resolve" "${target_url}${path}" || true)"
    if [[ "$code" =~ $expected_pattern ]]; then
      printf '%s\n' "$code"
      return 0
    fi
  done <<<"$targets"
  printf '%s\n' "${code:-000}"
  return 1
}

rollback_failure() {
  ROLLBACK_FAILED=1
  log "CRITICAL: rollback could not complete: $1. Recovery files will be preserved."
}

remove_phone_collector_service() {
  local unit="/etc/systemd/system/sls-mass-notify-phone-events.service" active_state
  [ -e "$unit" ] || [ -L "$unit" ] || return 0
  python3 -I - "$unit" <<'PY'
import os
import stat
import sys
path = sys.argv[1]
metadata = os.lstat(path)
if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
        or metadata.st_uid != 0 or metadata.st_mode & 0o022):
    raise SystemExit("Refusing to remove an unsafe phone collector service file.")
with open(path, encoding="utf-8") as handle:
    if handle.read(128).splitlines()[:2] != ["# Managed by SLS Mass Notify Server", "[Unit]"]:
        raise SystemExit("Refusing to remove an unrelated phone collector service.")
PY
  [ "$?" -eq 0 ] || return 1
  /usr/bin/timeout --kill-after=2 20 systemctl disable --now sls-mass-notify-phone-events.service || return 1
  active_state="$(/usr/bin/timeout --kill-after=2 10 systemctl show --property=ActiveState --value sls-mass-notify-phone-events.service)" || return 1
  case "$active_state" in inactive|failed) ;; *) log "The phone collector has not stopped; its service file was preserved."; return 1 ;; esac
  rm -- "$unit" || return 1
  /usr/bin/timeout --kill-after=2 10 systemctl daemon-reload || return 1
}

repair_restored_api_directory_access() {
  # Legacy installers create root-owned directories under umask027. Repair
  # only the two public API trees after proving they match the restored module.
  python3 -I - "/var/www/html/admin/modules/$MODULE" <<'PY'
import hashlib
import os
import stat
import sys
import time

flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
file_flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
directories = []
entries = 0
total_bytes = 0
started = time.monotonic()

def open_directory(path):
    current = os.open('/', flags)
    try:
        for component in path.split('/')[1:]:
            if component in {'', '.', '..'}:
                raise RuntimeError('invalid API directory')
            following = os.open(component, flags, dir_fd=current)
            os.close(current)
            current = following
        return current
    except BaseException:
        os.close(current)
        raise

def digest(directory, name):
    global total_bytes
    descriptor = os.open(name, file_flags, dir_fd=directory)
    with os.fdopen(descriptor, 'rb') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise RuntimeError('unsafe API file')
        total_bytes += metadata.st_size
        if total_bytes > 32 * 1024 * 1024:
            raise RuntimeError('API source comparison exceeds its limit')
        result = hashlib.sha256()
        read_bytes = 0
        while True:
            if time.monotonic() - started > 5:
                raise RuntimeError('API source comparison exceeds its limit')
            block = handle.read(65536)
            if not block:
                return result.digest()
            read_bytes += len(block)
            if read_bytes > metadata.st_size:
                raise RuntimeError('API file changed during source comparison')
            result.update(block)

def verify(source, target):
    global entries
    names = sorted(os.listdir(source))
    if names != sorted(os.listdir(target)):
        raise RuntimeError('API inventory differs from the restored source')
    directories.append(os.dup(target))
    for name in names:
        entries += 1
        if entries > 512 or time.monotonic() - started > 5:
            raise RuntimeError('API source comparison exceeds its limit')
        source_meta = os.stat(name, dir_fd=source, follow_symlinks=False)
        target_meta = os.stat(name, dir_fd=target, follow_symlinks=False)
        if stat.S_ISDIR(source_meta.st_mode) and stat.S_ISDIR(target_meta.st_mode):
            child_source = os.open(name, flags, dir_fd=source)
            try:
                child_target = os.open(name, flags, dir_fd=target)
                try:
                    verify(child_source, child_target)
                finally:
                    os.close(child_target)
            finally:
                os.close(child_source)
        elif stat.S_ISREG(source_meta.st_mode) and stat.S_ISREG(target_meta.st_mode):
            if digest(source, name) != digest(target, name):
                raise RuntimeError('API file differs from the restored source')
        else:
            raise RuntimeError('unsafe API entry')

try:
    for name, source_path, target_path in (('sipnotify', '/api/sipnotify', '/var/www/html/api/sipnotify'), ('sls-mass-notify', '/api/sls-mass-notify', '/var/www/html/api/sls-mass-notify'), ('operator-portal', '/portal', '/var/www/html/mass-notify')):
        try:
            source = open_directory(sys.argv[1] + source_path)
        except FileNotFoundError:
            # Signed releases from before the separate operator portal have
            # no portal tree. Their two API trees still require verification.
            if name == 'operator-portal':
                continue
            raise
        try:
            target = open_directory(target_path)
            try:
                if 'index.php' not in os.listdir(source):
                    raise RuntimeError('restored API source is incomplete')
                verify(source, target)
            finally:
                os.close(target)
        finally:
            os.close(source)
    # Every restored inventory must verify before any permission changes. Held directory
    # descriptors prevent symlink traversal if a pathname changes afterward.
    for descriptor in directories:
        os.fchmod(descriptor, 0o755)
        os.fsync(descriptor)
except (OSError, ValueError, RuntimeError):
    raise SystemExit('Unable to verify and restore public API directory access; recovery files were retained.') from None
finally:
    for descriptor in directories:
        os.close(descriptor)
PY
}

prepare_install_recovery() {
  RECOVERY_DIR="$(mktemp -d /var/lib/sls-mass-notify-recovery.XXXXXX)" || return 1
  chmod 0700 "$RECOVERY_DIR" || return 1
  /usr/bin/python3 -I "$IDLE_TOOL" --data "$DATA_DIR" inspect >"$RECOVERY_DIR/idle-before.json" || return 1
  chmod 0600 "$RECOVERY_DIR/idle-before.json" || return 1
  # Preserve only the module row, eight managed recording identities and
  # this module enrollment in existing backup jobs. Operational state remains live.
  php -r '
require "/etc/freepbx.conf";
$stmt = \FreePBX::Database()->prepare("SELECT * FROM modules WHERE modulename = ?");
$stmt->execute(["slsmassnotifyserver"]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
$filenames = ["custom/SLS_Mass_Notify_Paging_Tone_Opening", "custom/SLS_Mass_Notify_Paging_Tone_Closing", "custom/SLS_Mass_Notify_NWS_Alert", "custom/SLS_Mass_Notify_Lightning_Alert", "custom/Paging_Tone_Opening", "custom/Paging_Tone_Closing", "custom/NWS_alert", "custom/Lightning_alert"];
$recordings = [];
$lookup = \FreePBX::Database()->prepare("SELECT * FROM recordings WHERE filename = ? ORDER BY id");
foreach ($filenames as $filename) { $lookup->execute([$filename]); $recordings[$filename] = $lookup->fetchAll(\PDO::FETCH_ASSOC); if (count($recordings[$filename]) > 100) { throw new \RuntimeException("Too many rows use a reserved SLS recording name"); } }
$backup = \FreePBX::Create()->Backup;
$enrollment = [];
$enabled = static function ($value) { return in_array(strtolower(trim((string)$value)), ["1", "true", "yes", "on"], true); };
foreach (array_keys((array)$backup->getAll("backupList")) as $job) {
    $job = (string)$job;
    if ($job === "" || strlen($job) > 128 || preg_match("/[\x00-\x1f\x7f]/", $job)) { continue; }
    $selected = (array)$backup->getAll("modules_" . $job);
    if (!array_filter($selected, $enabled) || $enabled($selected["slsmassnotifyserver"] ?? false)) { continue; }
    $enrollment[] = ["job" => $job, "present" => array_key_exists("slsmassnotifyserver", $selected), "value" => $selected["slsmassnotifyserver"] ?? null];
}
$encoded = json_encode(["schema" => 2, "row" => $row ?: null, "recordings" => $recordings, "backup_enrollment" => $enrollment], JSON_THROW_ON_ERROR);
if (strlen($encoded) > 1048576) { throw new \RuntimeException("Scoped installation recovery metadata exceeds 1 MiB; no integration was changed"); }
echo $encoded;
' >"$RECOVERY_DIR/module-registration.json" || return 1
  chmod 0600 "$RECOVERY_DIR/module-registration.json" || return 1
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" --approval "$UPGRADE_TRUST_DIR/previous-root-approval.json" snapshot || return 1
}

verify_install_idle_history() {
  [ -n "${WORKER_LOCK_PID:-}" ] && kill -0 "$WORKER_LOCK_PID" 2>/dev/null || {
    log 'The installer worker guard exited unexpectedly; preserve recovery evidence.'
    return 1
  }
  /usr/bin/python3 -I "$IDLE_TOOL" --data "$DATA_DIR" inspect --baseline "$RECOVERY_DIR/idle-before.json" >/dev/null
}

restore_module_registration() {
  [ -n "$RECOVERY_DIR" ] && [ -f "$RECOVERY_DIR/module-registration.json" ] || return 1
  # Root supplies a read-only stdin descriptor; PHP runs as asterisk and never
  # receives access to the private recovery directory or executable snapshots.
  php -r '
require "/etc/freepbx.conf";
$raw = stream_get_contents(STDIN, 1048577);
if (strlen($raw) > 1048576) { throw new \RuntimeException("Oversized registration snapshot"); }
$data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if (($data["schema"] ?? null) !== 2 || !array_key_exists("row", $data)) { throw new \RuntimeException("Invalid registration snapshot"); }
$row = $data["row"];
if ($row !== null && (!is_array($row) || ($row["modulename"] ?? "") !== "slsmassnotifyserver")) { throw new \RuntimeException("Invalid module registration identity"); }
foreach (($row ?? []) as $column => $value) {
    if (!preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/D", $column) || !(is_scalar($value) || $value === null)) { throw new \RuntimeException("Invalid registration column"); }
}
$filenames = ["custom/SLS_Mass_Notify_Paging_Tone_Opening", "custom/SLS_Mass_Notify_Paging_Tone_Closing", "custom/SLS_Mass_Notify_NWS_Alert", "custom/SLS_Mass_Notify_Lightning_Alert", "custom/Paging_Tone_Opening", "custom/Paging_Tone_Closing", "custom/NWS_alert", "custom/Lightning_alert"];
if (!is_array($data["recordings"] ?? null) || array_keys($data["recordings"]) !== $filenames || !is_array($data["backup_enrollment"] ?? null)) { throw new \RuntimeException("Invalid scoped integration snapshot"); }
$validateRow = static function ($candidate) {
    if (!is_array($candidate) || !$candidate) { throw new \RuntimeException("Invalid recovery row"); }
    foreach ($candidate as $column => $value) {
        if (!preg_match("/^[A-Za-z_][A-Za-z0-9_]*$/D", $column) || !(is_scalar($value) || $value === null)) { throw new \RuntimeException("Invalid recovery column"); }
    }
};
foreach ($data["recordings"] as $filename => $rows) {
    if (!is_array($rows) || count($rows) > 100) { throw new \RuntimeException("Invalid recording recovery rows"); }
    foreach ($rows as $recording) { $validateRow($recording); if (($recording["filename"] ?? "") !== $filename) { throw new \RuntimeException("Recording recovery identity mismatch"); } }
}
foreach ($data["backup_enrollment"] as $entry) {
    if (!is_array($entry) || !is_string($entry["job"] ?? null) || $entry["job"] === "" || strlen($entry["job"]) > 128 || preg_match("/[\x00-\x1f\x7f]/", $entry["job"]) || !is_bool($entry["present"] ?? null) || !(is_scalar($entry["value"] ?? null) || ($entry["value"] ?? null) === null)) { throw new \RuntimeException("Invalid backup enrollment recovery entry"); }
}
$db = \FreePBX::Database();
$db->beginTransaction();
try {
    $db->prepare("DELETE FROM modules WHERE modulename = ?")->execute(["slsmassnotifyserver"]);
    if ($row !== null) {
        $columns = implode(",", array_map(static function ($value) { return "`" . $value . "`"; }, array_keys($row)));
        $marks = implode(",", array_fill(0, count($row), "?"));
        $db->prepare("INSERT INTO modules (" . $columns . ") VALUES (" . $marks . ")")->execute(array_values($row));
    }
    $lookup = $db->prepare("SELECT * FROM recordings WHERE filename = ? ORDER BY id");
    $delete = $db->prepare("DELETE FROM recordings WHERE filename = ?");
    foreach ($data["recordings"] as $filename => $rows) {
        $lookup->execute([$filename]);
        $current = $lookup->fetchAll(\PDO::FETCH_ASSOC);
        if ($current === $rows) { continue; }
        // New-release registration only adds reserved SLS rows and removes
        // legacy rows proven owned by this module. Refuse unrelated changes.
        if ($current && $rows) { throw new \RuntimeException("System Recording changed independently during installation: " . $filename); }
        if (!$rows && $current) {
            if (strpos($filename, "custom/SLS_Mass_Notify_") !== 0) { throw new \RuntimeException("Unexpected new legacy System Recording"); }
            foreach ($current as $recording) {
                if (strpos((string)($recording["displayname"] ?? ""), "SLS Mass Notify - ") !== 0 || strpos((string)($recording["description"] ?? ""), "Default Southland Servers ") !== 0) { throw new \RuntimeException("New System Recording is not owned by this installation"); }
            }
            $delete->execute([$filename]);
        }
        foreach ($rows as $recording) {
            $columns = implode(",", array_map(static function ($value) { return "`" . $value . "`"; }, array_keys($recording)));
            $marks = implode(",", array_fill(0, count($recording), "?"));
            $db->prepare("INSERT INTO recordings (" . $columns . ") VALUES (" . $marks . ")")->execute(array_values($recording));
        }
    }
    $db->commit();
} catch (\Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
$backup = \FreePBX::Create()->Backup;
$jobs = (array)$backup->getAll("backupList");
foreach ($data["backup_enrollment"] as $entry) {
    $job = $entry["job"];
    if (!array_key_exists($job, $jobs)) { continue; } // Never recreate a removed job.
    $selected = (array)$backup->getAll("modules_" . $job);
    if (($selected["slsmassnotifyserver"] ?? null) !== true) { continue; } // Preserve independent administrator edits.
    if ($entry["present"]) { $backup->setConfig("slsmassnotifyserver", $entry["value"], "modules_" . $job); }
    else { $backup->delConfig("slsmassnotifyserver", "modules_" . $job); }
}
require_once "/var/www/html/admin/modules/dashboard/classes/DashboardHooks.class.php";
$dashboard = \FreePBX::Dashboard();
$order = $dashboard->getConfig("visualorder");
$dashboard->setConfig("allhooks", \DashboardHooks::genHooks(is_array($order) ? $order : []));
' <"$RECOVERY_DIR/module-registration.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

restore_previous_module_tree() {
  /usr/bin/python3 -I - "$MODULE_BACKUP_DIR" "$HAD_EXISTING_MODULE" <<'PY'
import os, stat, sys
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC

def directory(path):
    fd = os.open('/', flags)
    try:
        for part in path.split('/'):
            if not part: continue
            if part in ('.', '..'): raise RuntimeError('invalid recovery directory')
            child = os.open(part, flags, dir_fd=fd)
            os.close(fd)
            fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise
backup = directory(sys.argv[1])
web = directory('/var/www/html/admin/modules')
try:
    info = os.fstat(backup)
    if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('unsafe module recovery directory')
    try:
        os.stat('failed-module', dir_fd=backup, follow_symlinks=False)
    except FileNotFoundError: pass
    else: raise RuntimeError('failed module recovery location already exists')
    try: os.rename('slsmassnotifyserver', 'failed-module', src_dir_fd=web, dst_dir_fd=backup)
    except FileNotFoundError: pass
    try: previous = os.stat('slsmassnotifyserver', dir_fd=backup, follow_symlinks=False)
    except FileNotFoundError:
        if sys.argv[2] == '1': raise RuntimeError('previous module backup is missing')
    else:
        if not stat.S_ISDIR(previous.st_mode): raise RuntimeError('previous module backup is unsafe')
        os.rename('slsmassnotifyserver', 'slsmassnotifyserver', src_dir_fd=backup, dst_dir_fd=web)
    os.fsync(web)
    os.fsync(backup)
finally:
    os.close(web)
    os.close(backup)
PY
}

rollback_module_install() {
  ROLLBACK_ATTEMPTED=1
  log "Restoring preserved static integration and module registration without running previous install/uninstall hooks."
  if [ -z "$RECOVERY_TOOL" ] || [ -z "$RECOVERY_DIR" ]; then
    rollback_failure "the authenticated recovery helper or snapshot is unavailable"
    return 1
  fi
  if [ "$MODULE_ACTIVATED" -eq 1 ] || { [ -n "$MODULE_BACKUP_DIR" ] && { [ -f "$MODULE_BACKUP_DIR/module-swap-complete" ] || [ -d "$MODULE_BACKUP_DIR/$MODULE" ] || { [ -n "$STAGING_DIR" ] && [ ! -d "$STAGING_DIR/$MODULE" ]; }; }; }; then
    restore_previous_module_tree || rollback_failure "the previous module directory could not be restored"
  fi
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" restore >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
    || rollback_failure "the static integration snapshot could not be fully restored"
  sls_as_asterisk /usr/sbin/asterisk -rx "manager reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || rollback_failure "restored Asterisk manager configuration did not reload"
  sls_as_asterisk /usr/sbin/asterisk -rx "dialplan reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || rollback_failure "restored Asterisk dialplan did not reload"
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" restore-services >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
    || rollback_failure "the prior collector/Apache process state could not be restored safely"
  restore_module_registration || rollback_failure "the original module registration and Dashboard hook index could not be restored"
  /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" verify >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
    || rollback_failure "restored static integration differs from its preserved snapshot"
  if [ "$HAD_EXISTING_MODULE" -eq 1 ] && [ "$ROLLBACK_FAILED" -eq 0 ]; then
    verify_local_api_route /api/sipnotify/desktop '^(401|429)$' '401/429' 'Recovered Desktop API' \
      || rollback_failure "the recovered Desktop API did not pass its read-only route check"
    verify_local_api_route /api/sls-mass-notify/ '^(401|403|405|429)$' '401/403/405/429' 'Recovered Control API' \
      || rollback_failure "the recovered Control API did not pass its read-only route check"
  fi
  verify_install_idle_history || rollback_failure "recovered notification idleness or retained phone history could not be verified"
  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    /usr/bin/python3 -I "$INSTALL_BOOTSTRAP_DIR/sls_upgrade_trust.py" --workspace "$UPGRADE_TRUST_DIR" --verify-previous >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
      || rollback_failure "the recovered SLS, Dashboard or Framework files differ from their pre-install authenticated approvals"
  fi
  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    /usr/bin/python3 -I "$RECOVERY_TOOL" --snapshot "$RECOVERY_DIR/static" restore-maintenance >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 \
      || rollback_failure "previous root maintenance could not be restored against its pre-install authenticated approval; root maintenance remains disabled"
  fi
  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    log "Rollback completed. The authenticated previous runtime and its maintenance schedule were restored; active/pending settings and delivery history were preserved."
    return 0
  fi
  return 1
}


guard_config_on_exit() {
  local status=$? current_hash
  INSTALL_EXIT_CODE="$status"
  trap - EXIT
  # A failed recovery command must not interrupt later config restoration or
  # delete the only recovery copy. Preserve the original install exit status.
  set +e

  if [ "$status" -ne 0 ] && [ "$SYSTEM_TIMEZONE_CHANGED" -eq 1 ] && [ -n "$ORIGINAL_SYSTEM_TIMEZONE" ]; then
    if set_system_timezone "$ORIGINAL_SYSTEM_TIMEZONE" \
      && timezone_names_equivalent "$(detect_system_timezone 2>/dev/null || true)" "$ORIGINAL_SYSTEM_TIMEZONE"; then
      log "The installer restored the original system timezone after the failed installation."
      SYSTEM_TIMEZONE_CHANGED=0
    else
      log "CRITICAL: the installer could not restore the original system timezone '$ORIGINAL_SYSTEM_TIMEZONE'."
      log "Run: timedatectl set-timezone $ORIGINAL_SYSTEM_TIMEZONE"
      rollback_failure "the original system timezone could not be restored"
    fi
  fi

  if [ -n "$CONFIG_SNAPSHOT" ] && [ -f "$CONFIG_SNAPSHOT" ]; then
    current_hash="$(safe_config_hash "$CONFIG_FILE" 2>/dev/null || true)"
    if [ "$current_hash" != "$CONFIG_HASH_BEFORE" ]; then
      if ensure_data_directory 2>/dev/null && safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE" 2>/dev/null; then
        log "The installer restored the original central config after an interrupted or failed install."
        [ "$status" -ne 0 ] || status=1
      else
        log "CRITICAL: the installer could not safely restore the protected central config. The root-owned snapshot remains at $CONFIG_SNAPSHOT."
        rollback_failure "the protected central configuration could not be restored"
        [ "$status" -ne 0 ] || status=1
      fi
    fi
  fi

  if [ "$PENDING_CONFIG_STATE" = present ] && ! verify_pending_config_unchanged; then
    if safe_config_restore "$PENDING_CONFIG_SNAPSHOT" "$PENDING_CONFIG_PATH"; then
      log 'Restored the original pending settings without applying them.'
      [ "$status" -ne 0 ] || status=1
    else
      rollback_failure "the original pending configuration could not be restored"
      [ "$status" -ne 0 ] || status=1
    fi
  elif [ "$PENDING_CONFIG_STATE" = absent ] && ! verify_pending_config_unchanged; then
    rollback_failure "unexpected pending settings were preserved for administrator review"
    [ "$status" -ne 0 ] || status=1
  fi

  if [ "$status" -ne 0 ] && [ "$STATIC_MUTATION_STARTED" -eq 1 ] && [ "$INSTALL_COMMITTED" -eq 0 ]; then
    rollback_module_install
  fi

  if [ "$ROLLBACK_ATTEMPTED" -eq 1 ] && [ -n "$CONFIG_HASH_BEFORE" ] && [ "$(safe_config_hash "$CONFIG_FILE" 2>/dev/null)" != "$CONFIG_HASH_BEFORE" ]; then
    rollback_failure "restoration changed the protected configuration"
    safe_config_restore "$CONFIG_SNAPSHOT" "$CONFIG_FILE" || log "CRITICAL: the original configuration requires manual restoration."
  fi

  if [ "$ROLLBACK_FAILED" -eq 0 ]; then
    if [ -n "$CONFIG_SNAPSHOT" ] && [ -f "$CONFIG_SNAPSHOT" ]; then
      rm -f "$CONFIG_SNAPSHOT"
      CONFIG_SNAPSHOT=""
    fi
    [ -z "$PENDING_CONFIG_SNAPSHOT" ] || rm -f -- "$PENDING_CONFIG_SNAPSHOT"
    [ -z "$STAGING_DIR" ] || rm -rf -- "$STAGING_DIR"
    [ -z "$MODULE_BACKUP_DIR" ] || rm -rf -- "$MODULE_BACKUP_DIR"
    [ -z "$RECOVERY_DIR" ] || rm -rf -- "$RECOVERY_DIR"
    [ -z "$INSTALL_BOOTSTRAP_DIR" ] || rm -rf -- "$INSTALL_BOOTSTRAP_DIR"
  else
    [ "$status" -ne 0 ] || status=1
    log "Recovery locations: module=${MODULE_BACKUP_DIR:-none}; config=${CONFIG_SNAPSHOT:-none}; pending=${PENDING_CONFIG_SNAPSHOT:-none}; stage=${STAGING_DIR:-none}; static=${RECOVERY_DIR:-none}; helper=${INSTALL_BOOTSTRAP_DIR:-none}."
    INSTALL_ERROR_CATEGORY="install_rollback_failed"
    if [ "$ROLLBACK_ATTEMPTED" -eq 1 ]; then
      INSTALL_SOLUTION="Recovery requires review: SLS root update/maintenance jobs remain disabled if prior authenticated execution approval or restoration could not be verified. Preserve the module, config, static-state and helper locations in the log; resolve any additional restoration failures before retrying."
    else
      INSTALL_SOLUTION="Protected preflight recovery was incomplete. Preserve the recovery locations printed in the installer log and correct the specified configuration or timezone restoration failure before retrying."
    fi
  fi
  [ -z "$DOWNLOAD_DIR" ] || rm -rf -- "$DOWNLOAD_DIR"

  if [ "$status" -ne 0 ]; then
    INSTALL_EXIT_CODE="$status"
    record_install_failure
  fi

  release_worker_coordination
  exit "$status"
}

timezone_zoneinfo_path() {
  local timezone_name="$1"

  TIMEZONE_NAME="$timezone_name" /usr/bin/python3 -I - <<'PY'
import os
import pathlib
import re

name = os.environ.get("TIMEZONE_NAME", "")
if not name or len(name) > 128 or "\x00" in name:
    raise SystemExit(1)
if name.startswith("/") or "//" in name or any(part in {"", ".", ".."} for part in name.split("/")):
    raise SystemExit(1)
if not re.fullmatch(r"[A-Za-z0-9._+-]+(?:/[A-Za-z0-9._+-]+)*", name):
    raise SystemExit(1)

base = pathlib.Path("/usr/share/zoneinfo").resolve(strict=True)
candidate = base.joinpath(*name.split("/"))
resolved = candidate.resolve(strict=True)
try:
    resolved.relative_to(base)
except ValueError:
    raise SystemExit(1)
if not resolved.is_file():
    raise SystemExit(1)
print(resolved)
PY
}

validate_system_timezone() {
  local timezone_name="$1"
  local listed_timezones

  timezone_zoneinfo_path "$timezone_name" >/dev/null 2>&1 || return 1
  [ -x /usr/bin/timedatectl ] || return 1
  listed_timezones="$(/usr/bin/timedatectl list-timezones --no-pager 2>/dev/null || true)"
  [ -n "$listed_timezones" ] || return 1
  LC_ALL=C grep -Fxq -- "$timezone_name" <<<"$listed_timezones"
}

detect_system_timezone() {
  local timezone_name=""

  if [ -x /usr/bin/timedatectl ]; then
    timezone_name="$(/usr/bin/timedatectl show --property=Timezone --value 2>/dev/null || true)"
  fi
  if ! timezone_zoneinfo_path "$timezone_name" >/dev/null 2>&1; then
    if [ -r /etc/timezone ] && [ ! -L /etc/timezone ]; then
      timezone_name="$(sed -n '1{s/[[:space:]]*$//;p;q;}' /etc/timezone)"
    fi
  fi
  timezone_zoneinfo_path "$timezone_name" >/dev/null 2>&1 || return 1
  printf '%s\n' "$timezone_name"
}

timezone_names_equivalent() {
  local left="$1"
  local right="$2"
  local left_path right_path

  [ -n "$left" ] && [ -n "$right" ] || return 1
  left_path="$(timezone_zoneinfo_path "$left" 2>/dev/null || true)"
  right_path="$(timezone_zoneinfo_path "$right" 2>/dev/null || true)"
  [ -n "$left_path" ] && [ "$left_path" = "$right_path" ]
}

set_system_timezone() {
  local timezone_name="$1"

  /usr/bin/timedatectl set-timezone "$timezone_name"
}

configure_system_timezone() {
  local detected_timezone requested_timezone response confirmed_timezone

  detected_timezone="$(detect_system_timezone 2>/dev/null || true)"
  [ -n "$detected_timezone" ] || {
    log "The installer could not detect a valid IANA system timezone through timedatectl and /usr/share/zoneinfo."
    return 1
  }
  ORIGINAL_SYSTEM_TIMEZONE="$detected_timezone"
  log "Detected system timezone: $detected_timezone"

  requested_timezone="$REQUESTED_SYSTEM_TIMEZONE"

  if [ -z "$requested_timezone" ]; then
    if [ ! -t 0 ] || [ ! -t 1 ]; then
      log "Noninteractive install: keeping $detected_timezone. Set SLS_MASS_NOTIFY_TIMEZONE to a listed IANA timezone to change it explicitly."
    else
      log "System timezone confirmed: $detected_timezone"
    fi
    CONFIRMED_SYSTEM_TIMEZONE="$detected_timezone"
    return 0
  fi

  if ! validate_system_timezone "$requested_timezone"; then
    log "The requested system timezone is not a valid listed IANA timezone."
    log "Choose an exact value from: timedatectl list-timezones"
    return 1
  fi
  if timezone_names_equivalent "$detected_timezone" "$requested_timezone"; then
    log "System timezone confirmed: $detected_timezone"
    CONFIRMED_SYSTEM_TIMEZONE="$detected_timezone"
    return 0
  fi

  log "Changing system timezone from $detected_timezone to $requested_timezone."
  set_system_timezone "$requested_timezone" || {
    log "timedatectl could not set the requested system timezone."
    return 1
  }
  SYSTEM_TIMEZONE_CHANGED=1
  confirmed_timezone="$(detect_system_timezone 2>/dev/null || true)"
  if ! timezone_names_equivalent "$confirmed_timezone" "$requested_timezone"; then
    log "System timezone verification failed after timedatectl returned success."
    if set_system_timezone "$detected_timezone" >/dev/null 2>&1 \
      && timezone_names_equivalent "$(detect_system_timezone 2>/dev/null || true)" "$detected_timezone"; then
      SYSTEM_TIMEZONE_CHANGED=0
    fi
    return 1
  fi
  CONFIRMED_SYSTEM_TIMEZONE="$confirmed_timezone"
  log "System timezone is now: $confirmed_timezone"
}

verify_confirmed_system_timezone() {
  local current_timezone

  [ -n "$CONFIRMED_SYSTEM_TIMEZONE" ] || return 1
  current_timezone="$(detect_system_timezone 2>/dev/null || true)"
  if ! timezone_names_equivalent "$current_timezone" "$CONFIRMED_SYSTEM_TIMEZONE"; then
    log "The system timezone changed unexpectedly during installation."
    return 1
  fi
  log "System timezone verified: $current_timezone"
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
  php -r '
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
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
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
  mkdir -p "$(dirname "$lock_file")"
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

  open_root_owned_file INSTALL_MAINTENANCE_LOCK_FD "$lock_file" || {
    log "The protected maintenance lock could not be opened safely."
    exit 1
  }
  flock -w 120 "$INSTALL_MAINTENANCE_LOCK_FD" || {
    log "Another Mass Notify maintenance or update operation is still running."
    log "Wait for it to finish, then rerun the installer."
    exit 1
  }
}

acquire_update_coordination() {
  local lock_file=/run/lock/sls-mass-notify-update.lock descriptor target
  for target in "/proc/${BASHPID}/fd/"*; do
    [ "$(readlink -f -- "$target" 2>/dev/null || true)" = "$lock_file" ] || continue
    descriptor="${target##*/}"
    if [[ "$descriptor" =~ ^[0-9]+$ ]] && flock -n "$descriptor"; then return 0; fi
  done
  open_root_owned_file INSTALL_UPDATE_LOCK_FD "$lock_file" || return 1
  flock -w 120 "$INSTALL_UPDATE_LOCK_FD" || { log 'Another SLS update is still running; no release files were changed.'; return 1; }
}

acquire_worker_coordination() {
  ensure_data_directory || return 1
  # A dedicated child owns all worker locks; no PHP/service process inherits
  # their descriptors. Root only opens validated service-account lock files.
  [ -n "$WORKER_GUARD_TOOL" ] && [ -f "$WORKER_GUARD_TOOL" ] && [ ! -L "$WORKER_GUARD_TOOL" ] || {
    log 'The authenticated worker guard helper has not been staged.'
    return 1
  }
  coproc SLS_INSTALL_WORKER_GUARD {
    /usr/bin/python3 -I "$WORKER_GUARD_TOOL" --data "$DATA_DIR" --timeout 120 hold
  }
  WORKER_LOCK_PID="$SLS_INSTALL_WORKER_GUARD_PID"
  exec {WORKER_LOCK_KEEPALIVE_FD}>&"${SLS_INSTALL_WORKER_GUARD[1]}"
  local status
  if ! IFS= read -r status <&"${SLS_INSTALL_WORKER_GUARD[0]}" || [ "$status" != ready ]; then
    log 'Unable to pause active notification workers safely. No release files were activated.'
    release_worker_coordination
    return 1
  fi
}

release_worker_coordination() {
  if [ -n "${WORKER_LOCK_KEEPALIVE_FD:-}" ]; then
    printf 'release\n' >&"$WORKER_LOCK_KEEPALIVE_FD" 2>/dev/null || true
    exec {WORKER_LOCK_KEEPALIVE_FD}>&-
  fi
  [ -z "${WORKER_LOCK_PID:-}" ] || wait "$WORKER_LOCK_PID" 2>/dev/null || true
  WORKER_LOCK_PID=""
}

close_inherited_maintenance_lock_fds() {
  local lock_file="${SLS_MASS_NOTIFY_MAINTENANCE_LOCK:-/run/lock/sls-mass-notify-maintenance.lock}"
  local descriptor descriptor_path descriptor_target close_fd

  for descriptor_path in "/proc/${BASHPID}/fd/"*; do
    [ -e "$descriptor_path" ] || continue
    descriptor="${descriptor_path##*/}"
    [[ "$descriptor" =~ ^[0-9]+$ ]] && [ "$descriptor" -gt 2 ] || continue
    descriptor_target="$(readlink -f -- "$descriptor_path" 2>/dev/null || true)"
    [ "$descriptor_target" = "$lock_file" ] || [ "$descriptor_target" = /run/lock/sls-mass-notify-update.lock ] || [ "$descriptor_target" = "$SETTINGS_LOCK" ] || continue
    close_fd="$descriptor"
    exec {close_fd}>&-
  done
}

run_without_install_maintenance_lock() (
  close_inherited_maintenance_lock_fds
  # A detached descendant must not keep the worker guard alive after our exit.
  if [ -n "${WORKER_LOCK_KEEPALIVE_FD:-}" ]; then
    exec {WORKER_LOCK_KEEPALIVE_FD}>&-
  fi
  "$@"
)

ensure_freepbx_prerequisites() {
  local prerequisite module_list
  module_list="$(fwconsole ma list 2>/dev/null)"
  for prerequisite in framework dashboard backup recordings; do
    if ! printf '%s\n' "$module_list" | grep -Eq "\\|[[:space:]]*${prerequisite}[[:space:]]*\\|"; then
      log "Installing required FreePBX module: $prerequisite"
      fwconsole ma --no-interaction --ignorecache downloadinstall "$prerequisite" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
        log "Required FreePBX module could not be installed: $prerequisite. See $LOG_FILE."
        exit 1
      }
    fi
    module_list="$(fwconsole ma list 2>/dev/null)"
    if ! printf '%s\n' "$module_list" | grep -Eq "\\|[[:space:]]*${prerequisite}[[:space:]]*\\|[^|]*\\|[[:space:]]*Enabled[[:space:]]*\\|"; then
      log "Enabling required FreePBX module: $prerequisite"
      fwconsole ma --no-interaction enable "$prerequisite" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
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
  apt-get update >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || return 1
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
    if asterisk -rx "core show version" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
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
  if ! asterisk -rx "core show version" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
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
    "$package_spec" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
      log "Exact-version Asterisk package repair failed for $package_spec. See $LOG_FILE."
      log "Restore that exact package version or its matching repository before rerunning the installer."
      return 1
    }
  [ -f "$provider_file" ] || {
    log "Package repair completed, but $provider_file is still missing."
    return 1
  }
  if ! wait_for_asterisk_cli; then
    log "Asterisk stopped during package repair; attempting its system service start without bootstrapping FreePBX as root."
    /usr/bin/systemctl start asterisk.service >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
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
  asterisk -rx "module load $module_name" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  if ! asterisk_module_loaded "$module_name" \
    && repair_asterisk_provider_package "$module_name"; then
    asterisk -rx "module load $module_name" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
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
  asterisk -rx "module load $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  if ! asterisk_capability_available "$capability_type" "$capability_name"; then
    asterisk -rx "module reload $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  fi
  if ! asterisk_capability_available "$capability_type" "$capability_name" \
    && repair_asterisk_provider_package "$provider_module"; then
    asterisk -rx "module load $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    if ! asterisk_capability_available "$capability_type" "$capability_name"; then
      asterisk -rx "module reload $provider_module" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
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
  ensure_asterisk_capability application AGI res_agi.so || return 1
  ensure_asterisk_capability application Playback app_playback.so || return 1
  ensure_asterisk_capability application Read app_read.so || return 1
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
  python3 -I - "$inventory_file" <<'PY'
import json
import sys

supported_formats = {
    "yealink", "yealink_text", "yealink_image", "cisco", "poly", "grandstream",
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
  # Contact-specific delivery is preferred, but portable endpoint fan-out with
  # generic XML is a safe runtime fallback and must not abort installation.
  return 0
}

bootstrap_resource_capacity() {
  # Standalone installers need this before a release archive is available.
  # Keep this copy identical to the packaged helper; a regression checks parity.
  /usr/bin/python3 -I - "$@" <<'SLS_RESOURCE_CAPACITY_PY'
#!/usr/bin/env python3
"""Read-only admission checks for provisional combined PBX/SLS resource tiers.

These engineering allocations do not certify delivery throughput. Desktop
transport retains its separate stream limit and requires JSON-poll fallback.
"""
import importlib.util
import argparse
import json
import os
from pathlib import Path

import re
import stat
import subprocess
import sys
import time

GIB = 1024 ** 3
MIB = 1024 ** 2
TIERS = ((50, 2, 4), (100, 3, 5), (250, 4, 6), (500, 6, 8), (1000, 8, 12))
REPORTING_PERCENT = 95
PHONE_BASELINE = 25
# 2026-10-02 private Asterisk: 25/50/100 PCM conference recipients used
# 0.058/0.133/0.242 cores and 35/41/52 MiB total RSS. Allow substantially more
# for SIP/SRTP, transcoding, caller setup bursts and normal PBX call traffic.
PHONE_RESOURCE_BASELINE = 100
PHONE_CONTACTS_PER_CPU = 200
PHONE_MEMORY_PER_CONTACT = 2 * MIB
PHONE_MEMORY_ROUNDING = 256 * MIB
# Reference measured 2026-10-02 on Debian12/CPython3.11/amd64 with every exact pin.
# The compressed wheel sizes are from the corresponding PyPI version JSON APIs.
PINNED_VENV_ALLOCATED = 217780224
PINNED_VENV_FILES = 4376
PINNED_WHEEL_BYTES = 77802965
PINNED_VERSIONS = {'pip': '26.2.1', 'setuptools': '84.0.0', 'wheel': '0.48.0',
                   'piper-tts': '1.8.0', 'onnxruntime': '1.30.0', 'numpy': '2.4.6',
                   'flatbuffers': '25.12.19', 'packaging': '26.3',
                   'pathvalidate': '3.3.1', 'protobuf': '7.36.2'}
VOICE_BYTES = {'en_US-lessac-low.onnx': 63201294, 'en_US-lessac-low.onnx.json': 4882,
               'en_US-lessac-medium.onnx': 63201294, 'en_US-lessac-medium.onnx.json': 4885,
               'en_US-amy-low.onnx': 63104526, 'en_US-amy-low.onnx.json': 4164,
               'en_US-ryan-low.onnx': 63104526, 'en_US-ryan-low.onnx.json': 4165,
               'es_ES-davefx-medium.onnx': 63201294,
               'es_ES-davefx-medium.onnx.json': 4817,
               'fr_FR-siwis-medium.onnx': 63201294,
               'fr_FR-siwis-medium.onnx.json': 4875,
               'de_DE-thorsten-low.onnx': 63104526,
               'de_DE-thorsten-low.onnx.json': 4159,
               'pt_BR-faber-medium.onnx': 63201294,
               'pt_BR-faber-medium.onnx.json': 4855}
MANAGED_TREES = {'runtime': '/usr/local/bin/sls_mass_notify',
                 'venv': '/usr/local/bin/sls_mass_notify/piper/venv',
                 'voices': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices',
                 'data': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin',
                 'config_backups': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/config-backups',
                 'audio': '/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts',
                 'module': '/var/www/html/admin/modules/slsmassnotifyserver',
                 'web': '/var/www/html/sls_mass_notify',
                 'assets': '/var/www/html/sls_mass_notify/assets',
                 'sip_api': '/var/www/html/api/sipnotify',
                 'control_api': '/var/www/html/api/sls-mass-notify', 'operator_portal': '/var/www/html/mass-notify'}
STATE_FILES = {'phone_ledger': 'phone-admission.json', 'weather_ledger': 'weather-delivery.json',
               'schedule_ledger': 'schedule-executions.json', 'config': 'mass-notifications.config'}
PERSISTENT_PATHS = ('/', '/var/lib/asterisk', '/usr/local', '/var/www', '/var/log')


def rounded(value, unit=4096):
    return (int(value) + unit - 1) // unit * unit


def storage_requirements(measured=None, missing_voices=None, allocation_units=None):
    """Explain each byte reservation; retained history is not a fixed quota.

    Installation workspaces may overlap bounded state updates/compaction.
    Rendering and retained media require separate measured provisioning: there
    is no enforced global synthesis limit or total retained-media byte quota.
    """
    measured = measured or {}
    units = allocation_units or {}
    size = lambda name: int(measured.get(name, {}).get('allocated_bytes', 0))
    unit = lambda path: max(4096, int(units.get(path, 4096)))
    # Accepted releases contain at most2000 entries and50MiB regular-file data.
    payload = lambda path: 50 * MIB + 2000 * unit(path)
    runtime, voices, data = (MANAGED_TREES[name] for name in ('runtime', 'voices', 'data'))
    reference_venv = PINNED_VENV_ALLOCATED + PINNED_VENV_FILES * (unit(runtime) - 4096)
    venv = max(reference_venv, size('venv'))
    voice_total = sum(rounded(value, unit(voices)) for value in VOICE_BYTES.values())
    model_temporary = rounded(max(VOICE_BYTES.values()), unit(voices))
    if missing_voices is None:
        missing_voices = voice_total
    # Phone/Weather 16 MiB; encrypted configuration 3 MiB; schedule reserve at least its10MiB backup
    #limit or its actual size. Up to25 queued jobs may write2MiB records.
    atomic_state = sum(max(limit * MIB, size(name)) for name, limit in
                       (('phone_ledger', 16), ('weather_ledger', 16), ('schedule_ledger', 10), ('config', 3))) + 25 * 2 * MIB
    # Retain 20 encrypted configuration backups, each bounded to 3 MiB.
    backup_growth = max(0, 20 * 3 * MIB - size('config_backups'))
    components = {
        runtime: {'replacement_runtime': max(size('runtime'), venv + payload(runtime))},
        voices: {'missing_or_replacement_voices': max(int(missing_voices), model_temporary)},
        data: {'bounded_atomic_state_writes': atomic_state},
        MANAGED_TREES['config_backups']: {'remaining_bounded_config_backups': backup_growth},
        MANAGED_TREES['module']: {'new_module_payload': payload(MANAGED_TREES['module'])},
        #Independent target mounts each need room for a legal package payload.
        MANAGED_TREES['web']: {'public_asset_payload': payload(MANAGED_TREES['web'])},
        MANAGED_TREES['sip_api']: {'sip_api_payload': payload(MANAGED_TREES['sip_api'])},
        MANAGED_TREES['control_api']: {'control_api_payload': payload(MANAGED_TREES['control_api'])},
        MANAGED_TREES['operator_portal']: {'operator_portal_payload': payload(MANAGED_TREES['operator_portal'])},
        #Maintenance compacts logs sequentially, using backup+retained temporary.
        '/var/log': {'event_log_backup_and_retained_copy': 2 * 64 * MIB},
        '/tmp': {'release_download_and_metadata': 52 * MIB + 16 * 1024 + 4096,
                 'extracted_release': payload('/tmp'), 'failed_release_recovery': payload('/tmp'),
                 'old_module_recovery': size('module'), 'protected_config_snapshot': 16 * MIB,
                 'pinned_wheel_downloads': rounded(PINNED_WHEEL_BYTES, unit('/tmp')),
                 'wheel_unpack_workspace': venv, 'pip_old_package_recovery': venv,
                 'one_voice_download': rounded(max(VOICE_BYTES.values()), unit('/tmp'))},
    }
    budgets = {path: sum(parts.values()) for path, parts in components.items()}
    fixed_names = ('runtime', 'voices', 'module', 'assets', 'sip_api', 'control_api')
    fixed = sum(size(name) for name in fixed_names)
    mutable = max(0, size('data') - size('voices')) + max(0, size('web') - size('assets'))
    return {'basis': 'measured_managed_trees_and_pinned_catalog_2026-09-20',
            'measured_fixed_allocation_bytes': fixed, 'measured_mutable_data_and_media_bytes': mutable,
            'measured_trees': measured, 'fresh_reference_voice_bytes': voice_total,
            'pinned_runtime_reference_bytes': reference_venv, 'pinned_wheel_download_bytes': PINNED_WHEEL_BYTES,
            'component_budgets_bytes': components, 'free_budgets_bytes': budgets,
            'sls_persistent_free_bytes': sum(value for path, value in budgets.items() if path != '/tmp'),
            'temporary_free_bytes': budgets['/tmp'],
            'additional_free_bytes': sum(budgets.values()),
            'steady_state_reserve_bytes': atomic_state + backup_growth + 2 * 64 * MIB,
            'retention_is_byte_bounded': False, 'synthesis_concurrency_is_enforced': False,
            'render_workspace_formula': {'raw_pcm_bytes': 'duration_seconds * voice_sample_rate * 2 + WAV header',
                                         'pbx_pcm_bytes': 'duration_seconds * 8000 * 2 + WAV header',
                                         'provisioning': 'Reserve raw audio, converted speech, final sequence and temporary sequence for each simultaneously rendering job. Add retained output separately.'},
            'retention_provisioning': 'Add measured retained-data growth over the chosen retention window and the peak bytes created between successful cleanup runs. Active references can extend retention; add raw+converted+sequence workspace for each additional simultaneous speech pipeline.'}


FREE_BUDGETS = storage_requirements()['free_budgets_bytes']


class ResourceProbeError(ValueError):
    """Only locally constructed, operator-safe diagnostics may reach the UI."""
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def measure_tree(path, deadline, maximum_entries=50000):
    """Metadata only, without following directory/file links or printing names."""
    result = {'allocated_bytes': 0, 'logical_bytes': 0, 'files': 0, 'largest_file_bytes': 0}
    root = Path(path)
    if not root.exists():
        return result
    if root.is_symlink() or not root.is_dir():
        raise ValueError('An SLS storage root is not a real directory')
    seen, seen_directories, examined, pending = set(), set(), 0, [root]
    while pending:
        if time.monotonic() > deadline:
            raise ValueError('SLS storage measurement exceeded its time limit; review retained history and retry')
        directory = pending.pop()
        try:
            metadata = directory.lstat()
        except FileNotFoundError:
            # Another worker may retire an already enumerated terminal directory.
            continue
        identity = (metadata.st_dev, metadata.st_ino)
        if not stat.S_ISDIR(metadata.st_mode) or identity in seen_directories:
            continue
        seen_directories.add(identity)
        try:
            with os.scandir(directory) as entries:
                for entry in entries:
                    examined += 1
                    if examined > maximum_entries or time.monotonic() > deadline:
                        raise ValueError('SLS storage measurement exceeded its bounded scan; review retained history and retry')
                    try:
                        metadata = entry.stat(follow_symlinks=False)
                    except FileNotFoundError:
                        continue
                    if stat.S_ISDIR(metadata.st_mode):
                        pending.append(Path(entry.path))
                        continue
                    identity = (metadata.st_dev, metadata.st_ino)
                    if not stat.S_ISREG(metadata.st_mode) or identity in seen:
                        continue
                    seen.add(identity)
                    result['allocated_bytes'] += metadata.st_blocks * 512
                    result['logical_bytes'] += metadata.st_size
                    result['largest_file_bytes'] = max(result['largest_file_bytes'], metadata.st_size)
                    result['files'] += 1
        except PermissionError as error:
            relative = directory.relative_to(root).as_posix()
            detail = str(root)
            if re.fullmatch(r'[A-Za-z0-9_./-]{1,120}', relative) and relative != '.':
                detail += '/' + relative
            raise ResourceProbeError('storage_directory_unreadable',
                'The PBX account cannot read SLS storage at ' + detail +
                '. Run Repair Installation to restore directory traversal/read permissions, then retry the resource check.') from error
        except OSError as error:
            raise ValueError('Unable to measure an SLS storage directory safely') from error
    return result


def probe_storage():
    deadline = time.monotonic() + 2
    measured = {name: measure_tree(path, deadline) for name, path in MANAGED_TREES.items()}
    for key, name in STATE_FILES.items():
        try:
            metadata = Path(MANAGED_TREES['data'], name).lstat()
        except FileNotFoundError:
            continue
        if stat.S_ISREG(metadata.st_mode):
            measured[key] = {'allocated_bytes': metadata.st_blocks * 512, 'logical_bytes': metadata.st_size}
    units = {}
    for path in FREE_BUDGETS:
        existing = Path(path)
        while not existing.exists():
            existing = existing.parent
        units[path] = os.statvfs(existing).f_frsize
    missing = 0
    for name, expected in VOICE_BYTES.items():
        path = Path(MANAGED_TREES['voices'], name)
        try:
            metadata = path.lstat()
        except FileNotFoundError:
            metadata = None
        if metadata is None or not stat.S_ISREG(metadata.st_mode) or metadata.st_size != expected:
            missing += rounded(expected, max(4096, units[MANAGED_TREES['voices']]))
    return storage_requirements(measured, missing, units)


def read_limit(path):
    try:
        return path.read_text(encoding='ascii').strip()
    except FileNotFoundError:
        return None


def cgroup_directories():
    """Inspect the current cgroup and all visible parents, including v1 hosts."""
    entries = []
    for line in Path('/proc/self/cgroup').read_text().splitlines():
        _, controllers, relative = line.split(':', 2)
        parts = Path(relative).parts
        if '..' in parts or not relative.startswith('/'):
            raise ValueError('Invalid cgroup membership')
        if not controllers:
            entries.append((Path('/sys/fs/cgroup'), relative, 'v2'))
        else:
            for controller in controllers.split(','):
                if controller in {'memory', 'cpu'}:
                    root = Path('/sys/fs/cgroup') / controller
                    if controller == 'cpu' and not root.exists():
                        root = Path('/sys/fs/cgroup/cpu,cpuacct')
                    entries.append((root, relative, controller))
    for root, relative, kind in entries:
        current = root / relative.lstrip('/')
        while True:
            if current.is_dir():
                yield current, kind
            if current == root:
                break
            current = current.parent


def probe_compute():
    cpu = float(len(os.sched_getaffinity(0)))
    memory_text = Path('/proc/meminfo').read_text(encoding='ascii')
    match = re.search(r'^MemTotal:\s+(\d+)\s+kB$', memory_text, re.M)
    if not match or cpu < 1:
        raise ValueError('CPU or physical memory information is unavailable')
    memory = int(match.group(1)) * 1024
    for directory, kind in cgroup_directories():
        memory_value = read_limit(directory / ('memory.max' if kind == 'v2' else 'memory.limit_in_bytes')) if kind in {'v2', 'memory'} else None
        if memory_value is not None and memory_value != 'max':
            limit = int(memory_value)
            if 0 < limit < 2 ** 60:
                memory = min(memory, limit)
        if kind == 'v2':
            value = read_limit(directory / 'cpu.max')
            if value:
                quota, period = value.split()
                if quota != 'max':
                    cpu = min(cpu, int(quota) / int(period))
        elif kind == 'cpu':
            quota = read_limit(directory / 'cpu.cfs_quota_us')
            period = read_limit(directory / 'cpu.cfs_period_us')
            if quota is not None and period is not None and int(quota) > 0:
                cpu = min(cpu, int(quota) / int(period))
    if memory <= 0 or cpu <= 0:
        raise ValueError('Invalid effective resource limits')
    return cpu, memory


def filesystem_types():
    mounts = []
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        before, after = line.split(' - ', 1)
        mount = re.sub(r'\\([0-7]{3})', lambda match: chr(int(match.group(1), 8)), before.split()[4])
        mounts.append((mount.rstrip('/') or '/', after.split()[0]))
    return sorted(mounts, key=lambda item: len(item[0]), reverse=True)


def probe_filesystems(free_budgets=None):
    free_budgets = FREE_BUDGETS if free_budgets is None else free_budgets
    mounts = filesystem_types()
    devices = {}
    for path in dict.fromkeys((*PERSISTENT_PATHS, *free_budgets)):
        existing = Path(path)
        while not existing.exists():
            existing = existing.parent
        actual = str(existing.resolve())
        metadata = existing.stat()
        usage = os.statvfs(existing)
        kind = next((kind for mount, kind in mounts if actual == mount or actual.startswith(mount.rstrip('/') + '/')), '')
        persistent = path in PERSISTENT_PATHS and kind not in {'tmpfs', 'ramfs', 'devtmpfs'}
        record = devices.setdefault(metadata.st_dev, {
            'device': str(metadata.st_dev), 'paths': [], 'total_bytes': usage.f_blocks * usage.f_frsize,
            'available_bytes': usage.f_bavail * usage.f_frsize, 'required_free_bytes': 0, 'persistent': False,
        })
        record['paths'].append(path)
        # Shared mounts and bind mounts must not count the same bytes twice.
        record['available_bytes'] = min(record['available_bytes'], usage.f_bavail * usage.f_frsize)
        record['total_bytes'] = min(record['total_bytes'], usage.f_blocks * usage.f_frsize)
        record['required_free_bytes'] += free_budgets.get(path, 0)
        record['persistent'] = record['persistent'] or persistent
    return list(devices.values())


def configured_limits(path):
    """Read only the capacity fields; never echo configuration or credentials."""
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('Configuration path must be absolute')
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for component in path.parts[1:-1]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            os.close(parent)
            parent = child
        descriptor = os.open(path.name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=parent)
    finally:
        os.close(parent)
    with os.fdopen(descriptor, 'rb') as handle:
        metadata = os.fstat(handle.fileno())
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_size > 16 * 1024 * 1024:
            raise ValueError('Configuration is not a bounded private regular file')
        raw = handle.read(16 * 1024 * 1024 + 1)
    if len(raw) > 16 * 1024 * 1024:
        raise ValueError('Configuration exceeded its size limit')
    value = json.loads(raw)
    if isinstance(value, dict) and any(field in value for field in ('format', 'ciphertext', 'key_id', 'nonce')):
        if value.get('format') != 'sls-mass-notify-config-aes256gcm-v1':
            raise ValueError('The protected configuration encryption format is unsupported or damaged')
        helper = Path(__file__).resolve().with_name('sls_config_crypto.py')
        if not helper.is_file():
            helper = Path('/usr/local/bin/sls_mass_notify/sls_config_crypto.py')
        for protected in [helper, *helper.parents][:-1]:
            metadata = protected.lstat()
            if metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not (stat.S_ISDIR(metadata.st_mode) and metadata.st_mode & stat.S_ISVTX)) or stat.S_ISLNK(metadata.st_mode):
                raise ValueError('Installed encryption helper is not protected')
        sys.dont_write_bytecode = True
        spec = importlib.util.spec_from_file_location('sls_config_crypto', helper)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        value = module.decode_config(raw)
    if not isinstance(value, dict):
        raise ValueError('Configuration must be an object')
    capacities = {key: value.get(key, default) for key, default in
                  (('desktop_client_limit', 25), ('phone_device_limit', PHONE_BASELINE))}
    if any(type(limit) is not int or not 1 <= limit <= 1000 for limit in capacities.values()):
        raise ValueError('Invalid notification capacity')
    return capacities


def rounded_phone_capacity(count):
    """Retain 25 for small installations, then round up in blocks of 50."""
    if type(count) is not int or count < 0:
        raise ValueError('Invalid detected phone count')
    if count > 1000:
        raise ResourceProbeError('phone_inventory_exceeds_limit',
            'More than 1000 internal phone contacts were detected. SLS supports at most 1000 simultaneous contacts; review the device inventory before installation.')
    return PHONE_BASELINE if count <= PHONE_BASELINE else rounded(count, 50)


def internal_phone_inventory(device_output, contact_output):
    """Count FreePBX PJSIP devices and their contacts, excluding trunk AORs.

    An offline configured device reserves one slot. Shared endpoint aliases do
    not count twice; several contacts on the same endpoint each count once.
    Complete CLI summaries are required so truncated output cannot undercount.
    Only aggregate counts leave this function, never addresses or device names.
    """
    lines = [line.strip() for line in device_output.splitlines() if line.strip()]
    rows = [line for line in lines if line.startswith('/DEVICE/')]
    if not lines or not re.fullmatch(r'[0-9]+ results found\.', lines[-1]) or int(lines[-1].split()[0]) != len(rows):
        raise ValueError('Incomplete FreePBX device inventory')
    endpoints = set()
    for line in rows:
        match = re.fullmatch(r'/DEVICE/[0-9]+/dial\s*:\s*(.*)', line)
        if not match or not match[1].startswith('PJSIP/'):
            continue
        endpoint = re.fullmatch(r'PJSIP/([A-Za-z0-9_.-]{1,80})', match[1])
        if not endpoint:
            raise ValueError('Unsupported internal PJSIP device route')
        endpoints.add(endpoint[1])
    if not endpoints:
        return {'configured_pjsip_devices': 0, 'registered_phone_contacts': 0, 'detected_phone_contacts': 0}
    contact_lines = [line.strip() for line in contact_output.splitlines() if line.strip()]
    summaries = [line for line in contact_lines if re.fullmatch(r'Objects found: [0-9]+', line)
                 or line in ('No objects found.', 'No Contacts found.')]
    if len(summaries) != 1:
        raise ValueError('Incomplete PJSIP contact inventory')
    expected = int(summaries[0].split()[-1]) if summaries[0].startswith('Objects found:') else 0
    contacts, counts = [], {endpoint: 0 for endpoint in endpoints}
    for line in contact_lines:
        if not line.startswith('Contact:'):
            continue
        fields = line.split()
        if len(fields) > 1 and fields[1].startswith('<'):
            continue
        if len(fields) != 5 or '/' not in fields[1] or not re.fullmatch(r'[a-fA-F0-9]{8,64}', fields[2]):
            raise ValueError('Unrecognized PJSIP contact inventory row')
        endpoint = fields[1].split('/', 1)[0]
        contacts.append((endpoint, fields[2]))
        if endpoint in counts:
            counts[endpoint] += 1
    if len(contacts) != expected or len(set(contacts)) != len(contacts):
        raise ValueError('Incomplete or duplicate PJSIP contact inventory')
    return {'configured_pjsip_devices': len(endpoints), 'registered_phone_contacts': sum(counts.values()),
            'detected_phone_contacts': sum(max(1, count) for count in counts.values())}


def detect_internal_phones():
    def command(value):
        result = subprocess.run(['/usr/sbin/asterisk', '-rx', value], capture_output=True,
                                text=True, timeout=3, check=False)
        if result.returncode != 0 or len(result.stdout) > 2 * MIB:
            raise ValueError('Asterisk inventory command failed')
        return result.stdout
    try:
        devices = command('database show DEVICE')
        # An empty DEVICE family needs no contact query. This also permits an
        # otherwise healthy new PBX whose PJSIP modules have no endpoints yet.
        empty = internal_phone_inventory(devices, 'Objects found: 0\n')
        return internal_phone_inventory(devices, command('pjsip show contacts')) if empty['configured_pjsip_devices'] else empty
    except (OSError, ValueError, subprocess.TimeoutExpired) as error:
        raise ResourceProbeError('phone_inventory_unavailable',
            'The internal phone inventory could not be verified. Confirm Asterisk is running and its DEVICE database and PJSIP contact commands are available, then retry installation. No phone capacity was guessed.') from error


def capacity_requirements(requested, phone_requested):
    if type(requested) is not int or not 1 <= requested <= 1000:
        raise ValueError('Desktop capacity must be between 1 and 1000')
    if type(phone_requested) is not int or not 1 <= phone_requested <= 1000:
        raise ValueError('Phone capacity must be between 1 and 1000')
    tier_limit, desktop_cpu, desktop_memory = next(tier for tier in TIERS if requested <= tier[0])
    extra_contacts = max(0, phone_requested - PHONE_RESOURCE_BASELINE)
    phone_cpu = (extra_contacts + PHONE_CONTACTS_PER_CPU - 1) // PHONE_CONTACTS_PER_CPU
    phone_memory = rounded(extra_contacts * PHONE_MEMORY_PER_CONTACT, PHONE_MEMORY_ROUNDING)
    return desktop_cpu + phone_cpu, desktop_memory * GIB + phone_memory, {
        'combined_baseline': {'cpu_count': 2, 'memory_bytes': 4 * GIB,
                              'desktop_clients': 50, 'phone_contacts': PHONE_RESOURCE_BASELINE},
        'desktop_growth': {'tier_limit': tier_limit, 'cpu_count': desktop_cpu - 2,
                           'memory_bytes': (desktop_memory - 4) * GIB},
        'phone_growth': {'contacts_over_baseline': extra_contacts, 'cpu_count': phone_cpu,
                         'memory_bytes': phone_memory},
    }


def evaluate(hardware, requested, phone_requested=PHONE_BASELINE):
    cpu, memory_bytes, components = capacity_requirements(requested, phone_requested)
    required_memory = memory_bytes * REPORTING_PERCENT // 100
    errors = []
    storage = hardware.get('storage', storage_requirements())

    def require(code, message, actual, required):
        if actual < required:
            errors.append({'code': code, 'message': message, 'actual': actual, 'required': required})

    require('cpu_insufficient',
            f'{cpu} effective CPU cores are required for {requested} desktops and {phone_requested} phone contacts; {hardware["effective_cpu_count"]:g} are allocated. Add CPU allocation or lower Desktop Capacity or Phone Capacity.',
            hardware['effective_cpu_count'], cpu)
    require('memory_insufficient',
            f'{memory_bytes / GIB:g} GiB RAM is required for {requested} desktops and {phone_requested} phone contacts; {hardware["effective_memory_bytes"] / GIB:.2f} GiB is usable. Add RAM or lower Desktop Capacity or Phone Capacity.',
            hardware['effective_memory_bytes'], required_memory)
    disks_ok = True
    for item in hardware['filesystems']:
        before = len(errors)
        require('free_space_insufficient',
                f'{item["required_free_bytes"] / GIB:.1f} GiB of additional SLS free space is required on the filesystem containing {", ".join(item["paths"])}; {item["available_bytes"] / GIB:.1f} GiB is free. Free space or expand this filesystem.',
                item['available_bytes'], item['required_free_bytes'])
        disks_ok = disks_ok and len(errors) == before
    eligible = 0
    eligible_phone = 0
    if disks_ok:
        for maximum, _, _ in TIERS:
            tier_cpu, tier_memory, _ = capacity_requirements(maximum, phone_requested)
            if hardware['effective_cpu_count'] >= tier_cpu and hardware['effective_memory_bytes'] >= tier_memory * REPORTING_PERCENT // 100:
                eligible = maximum
        # Phone capacity is a continuous integer policy, not the desktop tiers.
        for phone_limit in range(1, 1001):
            phone_cpu, phone_memory, _ = capacity_requirements(requested, phone_limit)
            if hardware['effective_cpu_count'] >= phone_cpu and hardware['effective_memory_bytes'] >= phone_memory * REPORTING_PERCENT // 100:
                eligible_phone = phone_limit
    checks = {'cpu': {'ok': hardware['effective_cpu_count'] >= cpu,
                      'actual': hardware['effective_cpu_count'], 'required': cpu},
              'memory': {'ok': hardware['effective_memory_bytes'] >= required_memory,
                         'actual': hardware['effective_memory_bytes'], 'required': required_memory}}
    for name, paths in (('storage', set(storage['free_budgets_bytes']) - {'/tmp'}), ('temporary', {'/tmp'})):
        filesystems = [item for item in hardware['filesystems'] if paths.intersection(item['paths'])]
        covered = set(path for item in filesystems for path in item['paths'])
        checks[name] = {'ok': all(item['available_bytes'] >= item['required_free_bytes'] for item in filesystems)
                       if paths <= covered else None,
                       'filesystems': [{'paths': item['paths'], 'actual': item['available_bytes'],
                                        'required': item['required_free_bytes']} for item in filesystems]}
    return {
        'schema': 1, 'requested_limit': requested, 'eligible_limit': eligible, 'hardware': hardware,
        'requested_phone_limit': phone_requested, 'eligible_phone_limit': eligible_phone,
        'requirements': {'cpu_count': cpu, 'memory_bytes': memory_bytes,
                         'minimum_reported_memory_bytes': required_memory,
                         'components': components, 'disk_policy': 'additional_sls_free_headroom',
                         'sls_persistent_free_bytes': storage['sls_persistent_free_bytes'], 'temporary_free_bytes': storage['temporary_free_bytes'],
                         'free_budgets_bytes': storage['free_budgets_bytes'], 'storage': storage,
                         'reporting_allowance_percent': 100 - REPORTING_PERCENT},
        'assumptions': {'load_certified': False, 'phone_baseline_contacts': PHONE_BASELINE,
                        'additional_phone_contacts_per_cpu_core': PHONE_CONTACTS_PER_CPU,
                        'additional_phone_memory_bytes_per_contact': PHONE_MEMORY_PER_CONTACT,
                        'phone_memory_rounding_bytes': PHONE_MEMORY_ROUNDING, 'desktop_sse_limit': 32,
                        'included_phone_contacts': PHONE_RESOURCE_BASELINE,
                        'synthesis_concurrency_limited_by_this_check': False,
                        'whole_volume_size_is_enforced': False},
        'errors': errors,
        'resource_checks': checks,
        'warnings': ['Provisional combined PBX/SLS allocation; not load-certified. Phone growth reserves capacity for registered contacts and Local/Page overhead, not measured phones-per-core throughput. This check does not limit concurrent speech synthesis.',
                     f'SLS measured fixed files occupy {storage["measured_fixed_allocation_bytes"] / MIB:.1f} MiB. Additional installation and bounded-state workspace requires {storage["additional_free_bytes"] / MIB:.1f} MiB across the listed filesystems, including {storage["steady_state_reserve_bytes"] / MIB:.1f} MiB for bounded writes/compaction. Retention and concurrent speech require additional measured provisioning; these are not storage quotas or a guarantee for an arbitrary workload.',
                     'Desktop clients above the stream limit require cursor-aware JSON polling. Existing PBX calls, codecs, recording, voicemail, backups, and long retention need separate resource budgets.'],
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--config', type=Path)
    parser.add_argument('--desktop-limit', type=int)
    phone_options = parser.add_mutually_exclusive_group()
    phone_options.add_argument('--phone-limit', type=int)
    phone_options.add_argument('--auto-phone-limit', action='store_true',
                               help='Detect internal phones on a fresh install; retain saved capacities on upgrades')
    options = parser.parse_args(argv)
    requested = options.desktop_limit
    phone_requested = options.phone_limit
    stage = 'configuration'
    selection = None
    try:
        saved = configured_limits(options.config) if options.config else {}
        if requested is None:
            requested = saved.get('desktop_client_limit', 25)
        if phone_requested is None:
            phone_requested = saved.get('phone_device_limit', PHONE_BASELINE)
        if options.auto_phone_limit and not saved:
            stage = 'internal phone inventory'
            selection = detect_internal_phones()
            phone_requested = rounded_phone_capacity(selection['detected_phone_contacts'])
            selection.update({'source': 'internal_pjsip_inventory', 'rounded_phone_capacity': phone_requested})
        stage = 'CPU and memory'
        cpu, memory = probe_compute()
        stage = 'SLS storage'
        storage = probe_storage()
        stage = 'filesystem free space'
        result = evaluate({'effective_cpu_count': cpu, 'effective_memory_bytes': memory,
                           'filesystems': probe_filesystems(storage['free_budgets_bytes']), 'storage': storage}, requested, phone_requested)
        if selection is not None:
            result['auto_phone_selection'] = selection
    except (OSError, ValueError, ZeroDivisionError, StopIteration) as error:
        code = error.code if isinstance(error, ResourceProbeError) else 'resource_probe_failed'
        message = str(error) if isinstance(error, ResourceProbeError) else (
            'The ' + stage + ' resource check could not be completed. Check the protected configuration and storage permissions, then run Repair Installation before increasing capacity.')
        result = {'schema': 1, 'requested_limit': requested, 'eligible_limit': 0, 'hardware': {}, 'requirements': {},
                  'requested_phone_limit': phone_requested, 'eligible_phone_limit': 0,
                  'errors': [{'code': code, 'message': message, 'actual': None, 'required': None}], 'warnings': []}
        print(json.dumps(result, sort_keys=True))
        return 3
    print(json.dumps(result, sort_keys=True))
    return 2 if result['errors'] else 0


if __name__ == '__main__':
    sys.exit(main())
SLS_RESOURCE_CAPACITY_PY
}

preflight_hardware_requirements() {
  local report status
  local -a arguments=(--check --desktop-limit 25 --auto-phone-limit)
  if [ -e "$CONFIG_FILE" ] || [ -L "$CONFIG_FILE" ]; then
    arguments=(--check --config "$CONFIG_FILE")
  fi
  if report="$(bootstrap_resource_capacity "${arguments[@]}")"; then
    if [ ! -e "$CONFIG_FILE" ]; then
      remember_fresh_phone_capacity "$report" || return 1
    fi
    log "Combined PBX/SLS CPU/RAM and dedicated SLS free-space headroom verified (provisional allocation; not a load certification)."
    printf '%s\n' "$report" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
    return 0
  else
    status=$?
  fi
  log "The combined PBX/SLS CPU/RAM or dedicated SLS free-space requirements were not met. No dependency or module changes were started."
  log "$report"
  return "$status"
}

remember_fresh_phone_capacity() {
  local capacity
  capacity="$(/usr/bin/python3 -I -c '
import json, sys
report = json.loads(sys.argv[1])
selection = report["auto_phone_selection"]
count, limit = selection["detected_phone_contacts"], report["requested_phone_limit"]
assert type(count) is int and 0 <= count <= 1000
assert type(limit) is int and limit == (25 if count <= 25 else (count + 49) // 50 * 50)
assert selection["rounded_phone_capacity"] == limit and not report["errors"]
print(limit)
' "$1")" || { log 'The detected phone capacity could not be verified; no default was guessed.'; return 1; }
  FRESH_PHONE_CAPACITY="$capacity"
  log "Fresh-install phone capacity: $FRESH_PHONE_CAPACITY contacts (25 minimum, rounded up in blocks of 50; resources verified)."
}

verify_staged_hardware_requirements() {
  local helper="$STAGING_DIR/$MODULE/bin/sls_mass_notify/sls_resource_capacity.py"
  local report
  local -a arguments=(--check --desktop-limit 25 --auto-phone-limit)
  if [ -e "$CONFIG_FILE" ]; then
    arguments=(--check --config "$CONFIG_FILE")
  fi
  report="$(/usr/bin/python3 -I "$helper" "${arguments[@]}" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}")" || {
    log "$report"
    log "This PBX does not meet the configured desktop/phone capacities or SLS free-space requirements. See $LOG_FILE."
    return 1
  }
  printf '%s\n' "$report" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
  if [ ! -e "$CONFIG_FILE" ]; then
    remember_fresh_phone_capacity "$report"
  fi
}

preflight_platform() {
  local apache_module apache_modules available_kb check_path required_kb utility
  # Runtime and dialplan paths are conventional FreePBX paths. Detect a
  # relocated installation explicitly instead of creating a second, unused tree.
  /usr/bin/timeout 20 /usr/sbin/runuser -u asterisk -- /usr/bin/php <<'PHP' || {
<?php
require '/etc/freepbx.conf';
$expected = ['AMPWEBROOT'=>'/var/www/html', 'ASTETCDIR'=>'/etc/asterisk',
    'ASTSPOOLDIR'=>'/var/spool/asterisk', 'ASTVARLIBDIR'=>'/var/lib/asterisk',
    'AMPASTERISKUSER'=>'asterisk', 'AMPASTERISKGROUP'=>'asterisk'];
foreach ($expected as $key => $path) {
    $actual = rtrim((string)($amp_conf[$key] ?? ''), '/');
    if ($actual !== $path) {
        fwrite(STDERR, 'Unsupported FreePBX layout: ' . $key . ' must be ' . $path . '. No alternate runtime tree will be created.' . PHP_EOL);
        exit(1);
    }
}
exit(0);
PHP
    log "This FreePBX layout requires an explicitly supported path adapter; installation stopped before activation."
    exit 1
  }
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
  /usr/sbin/apache2ctl configtest >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
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
  asterisk -rx "core show version" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
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
  [ -x /usr/bin/timedatectl ] || missing_packages+=(systemd)
  [ -r /usr/share/zoneinfo/UTC ] || missing_packages+=(tzdata)

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

  for bootstrap_utility in /usr/bin/php /usr/bin/flock /usr/bin/readlink /usr/bin/timeout /usr/bin/timedatectl /usr/sbin/runuser; do
    [ -x "$bootstrap_utility" ] || {
      log "A required installation bootstrap prerequisite is unavailable after dependency installation: $bootstrap_utility"
      exit 1
    }
  done
  /usr/bin/php -r 'exit(function_exists("openssl_encrypt") && function_exists("openssl_decrypt") ? 0 : 1);' || {
    log "The PHP OpenSSL extension is required for protected desktop credentials. Install php-common, then rerun the installer."
    exit 1
  }
  [ -r /usr/share/zoneinfo/UTC ] || {
    log "The IANA timezone database is required. Install tzdata, then rerun the installer."
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
    [ -x /usr/bin/openssl ] || add_missing_package openssl
    [ -x /usr/sbin/logrotate ] || add_missing_package logrotate
    [ -x /usr/bin/python3 ] || add_missing_package python3
    if [ -x /usr/bin/python3 ]; then
      /usr/bin/python3 -I -c 'import venv' >/dev/null 2>&1 || add_missing_package python3-venv
      /usr/bin/python3 -I -m pip --version >/dev/null 2>&1 || add_missing_package python3-pip
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
      local sls_php_runtime_version
      sls_php_runtime_version="$(/usr/bin/php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
      [[ "$sls_php_runtime_version" =~ ^[0-9]+\.[0-9]+$ ]] || { log "Cannot identify the active PHP runtime for extension installation."; exit 1; }
      /usr/bin/php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-sqlite3"
      /usr/bin/php -r 'exit(function_exists("curl_init") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-curl"
      /usr/bin/php -r 'exit(class_exists("DOMDocument") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-xml"
      /usr/bin/php -r 'exit(extension_loaded("mbstring") && function_exists("mb_strlen") && function_exists("mb_substr") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-mbstring"
      /usr/bin/php -r 'exit(function_exists("posix_getpwnam") && function_exists("openssl_encrypt") && function_exists("openssl_decrypt") && function_exists("sodium_crypto_pwhash") && function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") ? 0 : 1);' >/dev/null 2>&1 || add_missing_package "php${sls_php_runtime_version}-common"
    else
      add_missing_package php-mbstring
      add_missing_package php-sqlite3
      add_missing_package php-curl
      add_missing_package php-xml
    fi
    /usr/bin/python3 -I -c 'from cryptography.hazmat.primitives.ciphers.aead import AESGCM' >/dev/null 2>&1 || add_missing_package python3-cryptography
    [ -x /usr/bin/node ] || add_missing_package nodejs
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
  /usr/bin/php -r 'exit(extension_loaded("pdo_sqlite") && function_exists("curl_init") && class_exists("DOMDocument") ? 0 : 1);' || {
    log "The active PHP CLI still lacks SQLite, cURL or XML after dependency installation. Enable the matching PHP-version extensions and verify the PBX web PHP runtime uses them too. No module activation was attempted."
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
  /usr/bin/php -r 'exit(function_exists("sodium_crypto_pwhash") && function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") ? 0 : 1);' || {
    log "PHP Sodium is unavailable for authenticated encrypted configuration backups. Enable sodium for the installed PHP CLI and PBX web PHP versions, then rerun the installer. The protected configuration was not rewritten."
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

fetch_release_asset() {
  SLS_ASSET_URL="$1" SLS_ASSET_OUTPUT="$2" SLS_ASSET_LIMIT="$3" SLS_ASSET_TOKEN="$TOKEN" /usr/bin/timeout --signal=TERM --kill-after=5 905 /usr/bin/python3 -I - <<'PY'
import os
import re
import socket
import time
import urllib.error
import urllib.parse
import urllib.request

url = os.environ['SLS_ASSET_URL']
pattern = (r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/'
           r'slsmassnotifyserver-([0-9]+\.[0-9]+\.[0-9]+(?:-beta)?)/'
           r'(?:slsmassnotifyserver-\1\.tgz|release-manifest\.json|release-manifest\.sig)')
if not re.fullmatch(pattern, url):
    raise SystemExit('Use a signed HTTPS GitHub release URL, or an offline TGZ with an explicitly trusted SHA-256.')
limit = int(os.environ['SLS_ASSET_LIMIT'])
if not 1 <= limit <= 52 * 1024 * 1024:
    raise SystemExit('Invalid release download limit.')
token = os.environ.get('SLS_ASSET_TOKEN', '')
if any(ord(character) < 32 or ord(character) == 127 for character in token):
    raise SystemExit('Invalid GitHub token format.')

class ReleaseRedirects(urllib.request.HTTPRedirectHandler):
    max_redirections = 5
    def redirect_request(self, request, response, code, message, headers, new_url):
        target = urllib.parse.urlsplit(new_url)
        if (target.scheme != 'https' or target.username or target.password
                or target.port not in (None, 443) or target.fragment
                or target.hostname not in {'github.com', 'objects.githubusercontent.com',
                                           'release-assets.githubusercontent.com'}):
            raise ValueError('Release download redirected outside approved HTTPS asset hosts.')
        redirected = super().redirect_request(request, response, code, message, headers, new_url)
        # Authentication applies only to the original GitHub request. Signed
        # asset redirects do not need it, including a later redirect back.
        if redirected is not None:
            redirected.remove_header('Authorization')
        return redirected

request = urllib.request.Request(url, headers={
    'User-Agent': 'SLS-Mass-Notify-Installer', 'Accept': 'application/octet-stream',
    'Accept-Encoding': 'identity',
})
if token:
    request.add_unredirected_header('Authorization', 'Bearer ' + token)
opener = urllib.request.build_opener(ReleaseRedirects())
path = os.environ['SLS_ASSET_OUTPUT']
deadline = time.monotonic() + 900
try:
    # The caller owns the private parent directory; never truncate or follow
    # a pre-existing pathname, even inside that directory.
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as output:
        for attempt in range(5):
            try:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError('Release download deadline exceeded.')
                with opener.open(request, timeout=min(20, remaining)) as response:
                    length = response.headers.get('Content-Length')
                    if length is not None and (not length.isdigit() or int(length) > limit):
                        raise ValueError('Release asset exceeds its download limit.')
                    total = 0
                    while True:
                        if time.monotonic() >= deadline:
                            raise TimeoutError('Release download deadline exceeded.')
                        chunk = response.read(min(65536, limit - total + 1))
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > limit:
                            raise ValueError('Release asset exceeds its download limit.')
                        output.write(chunk)
                    if total == 0 or (length is not None and total != int(length)):
                        raise ValueError('Release asset was empty or incomplete.')
                output.flush()
                os.fsync(output.fileno())
                break
            except (urllib.error.URLError, socket.timeout, TimeoutError):
                if attempt == 4 or time.monotonic() >= deadline:
                    raise
                output.seek(0)
                output.truncate()
                time.sleep(min(2 ** attempt, max(0, deadline - time.monotonic())))
except Exception:
    # Do not include redirected URLs, signed query parameters, or headers in
    # diagnostics. The caller removes the private directory on failure.
    raise SystemExit('Release download failed validation, exceeded its size/time limit, or could not be completed.')
PY
}

copy_private_release_asset() {
  /usr/bin/python3 -I - "$1" "$2" "$3" <<'PYASSET'
import os, stat, sys
source, target, bound = sys.argv[1], sys.argv[2], int(sys.argv[3])
fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
try:
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 0 < info.st_size <= bound:
        raise RuntimeError('release asset must be a bounded, single-link regular file')
    out = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(out, 'wb') as handle:
        total = 0
        while True:
            block = os.read(fd, 65536)
            if not block: break
            total += len(block)
            if total > bound: raise RuntimeError('release asset exceeds size limit')
            handle.write(block)
        handle.flush()
        os.fsync(handle.fileno())
    after = os.fstat(fd)
    if total != info.st_size or (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
        raise RuntimeError('release asset changed during private copy')
finally:
    os.close(fd)
PYASSET
}

download_tgz() {
  local original_tgz="$TGZ"
  DOWNLOAD_DIR="$(mktemp -d /tmp/sls-mass-notify-download.XXXXXX)" || return 1
  if [ -n "$URL" ]; then
    TGZ="$DOWNLOAD_DIR/package.tgz"
    fetch_release_asset "$URL" "$TGZ" 54525952 || return 1
  else
    TGZ="$DOWNLOAD_DIR/package.tgz"
    copy_private_release_asset "$original_tgz" "$TGZ" 54525952 || return 1
  fi

  [ -f "$TGZ" ] && [ ! -L "$TGZ" ] && [ -s "$TGZ" ] || {
    log "$TGZ is missing. Set SLS_MASS_NOTIFY_TGZ_URL or upload the TGZ to this path first."
    exit 1
  }
  chmod 0600 "$TGZ"
}

verify_tgz() {
  actual_sha="$(sha256sum "$TGZ" | awk '{print $1}')"
  if [ -n "$SHA256" ]; then
    echo "$SHA256  $TGZ" | sha256sum -c -
  elif [ "$(basename "$TGZ")" = "slsmassnotifyserver-0.1.5-beta.tgz" ] && [ "$actual_sha" != "$EXPECTED_TGZ_SHA256" ]; then
		log "$TGZ does not match the current slsmassnotifyserver-0.1.5-beta package."
    log "Expected SHA256: $EXPECTED_TGZ_SHA256"
    log "Actual SHA256:   $actual_sha"
    log "Remove the stale local TGZ or install with SLS_MASS_NOTIFY_TGZ_URL so the current release is downloaded."
    exit 1
  else
    printf '%s  %s\n' "$actual_sha" "$TGZ"
  fi
  if [ -n "$URL" ]; then
    verify_publisher_release
  else
    [ -n "$SHA256" ] || { log 'A local/offline TGZ requires an explicit trusted SLS_MASS_NOTIFY_SHA256.'; return 1; }
    verify_publisher_release
  fi
  TGZ_PATH="$TGZ" MODULE_NAME="$MODULE" python3 -I - <<'PY'
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
    if (root.findtext("version") or "").strip() != "0.1.5-beta":
        raise SystemExit("module.xml does not contain the expected 0.1.5-beta version")
PY
}

verify_publisher_release() {
  [ -n "$DOWNLOAD_DIR" ] && [ -d "$DOWNLOAD_DIR" ] || return 1
  if [ -n "$URL" ]; then
    fetch_release_asset "${URL%/*}/release-manifest.json" "$DOWNLOAD_DIR/release-manifest.json" 16384 || return 1
    fetch_release_asset "${URL%/*}/release-manifest.sig" "$DOWNLOAD_DIR/release-manifest.sig" 64 || return 1
  else
    copy_private_release_asset "${SLS_MASS_NOTIFY_MANIFEST:-${OFFLINE_ASSET_DIR}/release-manifest.json}" "$DOWNLOAD_DIR/release-manifest.json" 16384 || return 1
    copy_private_release_asset "${SLS_MASS_NOTIFY_MANIFEST_SIGNATURE:-${OFFLINE_ASSET_DIR}/release-manifest.sig}" "$DOWNLOAD_DIR/release-manifest.sig" 64 || return 1
  fi
  SLS_RELEASE_DIRECTORY="$DOWNLOAD_DIR" SLS_RELEASE_URL="$URL" SLS_RELEASE_TGZ="$TGZ" SLS_RELEASE_INSTALLER="$INSTALLER_SOURCE_PATH" /usr/bin/python3 -I - <<'PY'
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time

url = os.environ['SLS_RELEASE_URL']
match = re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/slsmassnotifyserver-([0-9]+\.[0-9]+\.[0-9]+(?:-beta)?)/(slsmassnotifyserver-\1\.tgz)', url)
if url and not match:
    raise SystemExit('Use a signed GitHub release URL or a signed offline package.')
version, package = match.groups() if match else ('0.1.5-beta', 'slsmassnotifyserver-0.1.5-beta.tgz')
public_key = b'-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAFMDOgOaBGcaI8d+v0w/NX4RbwGlBsoktNc2V2F8UkUQ=\n-----END PUBLIC KEY-----\n'
try:
    with tempfile.TemporaryDirectory(prefix='sls-release-verification-') as directory:
        root = Path(directory)
        for name, limit in [('release-manifest.json', 16384), ('release-manifest.sig', 64)]:
            body = (Path(os.environ['SLS_RELEASE_DIRECTORY']) / name).read_bytes()
            if len(body) > limit or (name.endswith('.sig') and len(body) != 64):
                raise ValueError('Invalid manifest/signature size')
            (root / name).write_bytes(body)
        trusted_helper = Path('/usr/local/bin/sls_mass_notify/sls_release_trust.py')
        trust_state = Path('/var/lib/sls-mass-notify-trust')
        if trusted_helper.exists() or trusted_helper.is_symlink():
            for path in (trusted_helper, *trusted_helper.parents):
                metadata = path.lstat()
                expected = stat.S_ISREG if path == trusted_helper else stat.S_ISDIR
                if not expected(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_mode & 0o022:
                    raise ValueError('Installed publisher verifier is not protected by root')
            sys.dont_write_bytecode = True
            spec = importlib.util.spec_from_file_location('installed_publisher_trust', trusted_helper)
            trust = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(trust)
            manifest = trust.authenticate_manifest((root / 'release-manifest.json').read_bytes(),
                (root / 'release-manifest.sig').read_bytes(), anchor=public_key)
        else:
            if any((trust_state / name).exists() or (trust_state / name).is_symlink()
                   for name in ('publisher-trust.json', 'release-trust.initialized')):
                raise ValueError('Established publisher trust requires its protected verifier; restore it before updating')
            (root / 'publisher.pub').write_bytes(public_key)
            subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-verify', '-rawin', '-pubin', '-inkey', str(root / 'publisher.pub'),
                            '-in', str(root / 'release-manifest.json'), '-sigfile', str(root / 'release-manifest.sig')],
                           check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
            manifest = json.loads((root / 'release-manifest.json').read_bytes())
            signing = manifest.get('signing') if isinstance(manifest, dict) else None
            if signing is not None:
                # The bootstrap accepts only the pinned key, including for the
                # new metadata format. A downloaded policy cannot add authority.
                anchor_der = subprocess.run(['/usr/bin/openssl','pkey','-pubin','-outform','DER'],
                    input=public_key, check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=10).stdout
                now = int(time.time())
                if not isinstance(signing, dict) or set(signing) != {'key_id','issued_at','expires_at'} \
                        or signing['key_id'] != hashlib.sha256(anchor_der).hexdigest() \
                        or type(signing['issued_at']) is not int or type(signing['expires_at']) is not int \
                        or signing['issued_at'] < 0 or signing['issued_at'] > now + 300 \
                        or not signing['issued_at'] < signing['expires_at'] <= signing['issued_at'] + 2 * 366 * 86400 \
                        or now >= signing['expires_at']:
                    raise ValueError('Signed release metadata is expired or invalid')
        if not isinstance(manifest, dict) or manifest.get('schema') != 1 or manifest.get('version') != version \
                or manifest.get('tag') != 'slsmassnotifyserver-' + version or manifest.get('package') != package:
            raise ValueError('Signed release identity mismatch')
        for field, path in [('package_sha256', os.environ['SLS_RELEASE_TGZ']), ('installer_sha256', os.environ['SLS_RELEASE_INSTALLER'])]:
            with open(path, 'rb') as handle:
                actual = hashlib.file_digest(handle, 'sha256').hexdigest()
            if manifest.get(field) != actual:
                raise ValueError('Signed release artifact mismatch: ' + field)
except Exception as error:
    raise SystemExit('Publisher verification failed before activation: ' + str(error))
print('Publisher signature and installer/package hashes verified.')
PY
}

prepare_settings_lock() {
  [ ! -L "$CONFIG_FILE" ] || {
    log "Refusing to install while the protected central configuration is a symbolic link."
    exit 1
  }
  [ -e "$CONFIG_FILE" ] || return 0
  SETTINGS_LOCK_PATH="$SETTINGS_LOCK" /usr/bin/python3 -I - <<'PY'
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
  flock -w 120 -x "$CONFIG_LOCK_FD" || { log "The configuration editor did not become idle within 120 seconds; no settings were replaced."; return 1; }
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
  CONFIG_SOURCE_PATH="$source_path" CONFIG_SNAPSHOT_PATH="$snapshot_path" /usr/bin/python3 -I - <<'PY'
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
  CONFIG_SOURCE_PATH="$1" /usr/bin/python3 -I - <<'PY'
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
  CONFIG_SNAPSHOT_PATH="$snapshot_path" CONFIG_DESTINATION_PATH="$destination_path" /usr/bin/python3 -I - <<'PY'
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

snapshot_pending_config() {
  PENDING_CONFIG_PATH="$DATA_DIR/mass-notifications.pending.config"
  PENDING_CONFIG_STATE=absent
  if [ -e "$PENDING_CONFIG_PATH" ] || [ -L "$PENDING_CONFIG_PATH" ]; then
    PENDING_CONFIG_SNAPSHOT="$(mktemp /tmp/slsmassnotifyserver-pending.XXXXXX)" || return 1
    PENDING_CONFIG_HASH="$(safe_config_snapshot "$PENDING_CONFIG_PATH" "$PENDING_CONFIG_SNAPSHOT")" || {
      log 'Pending configuration is unsafe or unreadable. It was not applied or replaced.'
      return 1
    }
    PENDING_CONFIG_STATE=present
  fi
}

verify_pending_config_unchanged() {
  if [ "$PENDING_CONFIG_STATE" = present ]; then
    [ "$(safe_config_hash "$PENDING_CONFIG_PATH" 2>/dev/null || true)" = "$PENDING_CONFIG_HASH" ] || {
      log 'Pending settings changed during installation; the installer will not apply them.'
      return 1
    }
  elif [ "$PENDING_CONFIG_STATE" = absent ]; then
    [ ! -e "$PENDING_CONFIG_PATH" ] && [ ! -L "$PENDING_CONFIG_PATH" ] || {
      log 'A pending configuration appeared during installation; preserve it for administrator review.'
      return 1
    }
  fi
}

initialize_fresh_install_config() {
  [ ! -e "$CONFIG_FILE" ] && [ ! -L "$CONFIG_FILE" ] || return 0
  [ "$HAD_EXISTING_MODULE" -eq 0 ] && [ ! -d "$MODULE_BACKUP_DIR/$MODULE" ] || {
    log 'An existing module has no active configuration. Restore its protected configuration backup before upgrading; existing credentials will not be silently regenerated.'
    return 1
  }
  local initialization_result
  initialization_result="$(SLS_INIT_PHONE_CAPACITY="$FRESH_PHONE_CAPACITY" php -r '
require "/etc/freepbx.conf";
require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php";
$class = "\\FreePBX\\modules\\Slsmassnotifyserver";
$obj = new $class(\FreePBX::Create());
$capacity = getenv("SLS_INIT_PHONE_CAPACITY");
if (!preg_match("/^[0-9]{1,4}$/D", (string)$capacity) || (int)$capacity < 25 || (int)$capacity > 1000) { exit(1); }
echo $obj->initializeFreshInstallerConfiguration((int)$capacity) ? "created" : "existing";
')" || return 1
  case "$initialization_result" in
    created) FRESH_CONFIG_INITIALIZED=1 ;;
    existing) FRESH_CONFIG_INITIALIZED=0 ;;
    *) log "Fresh configuration initialization did not return a verified result."; return 1 ;;
  esac
  # No config lock was held while the file was absent. Freeze the completed
  # initial file before module installation/reload can observe it.
  acquire_settings_coordination
  snapshot_config
  verify_fresh_install_defaults
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
require_once (getenv("SLS_CONFIG_CRYPTO_PHP") ?: "/usr/local/bin/sls_mass_notify/sls_config_crypto.php");
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents($path));
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

validate_staged_central_config() {
  [ -n "$CONFIG_HASH_BEFORE" ] || return 0
  if /usr/bin/python3 -I "$STAGING_DIR/$MODULE/bin/sls_mass_notify/sls_config.py" "$CONFIG_FILE" >/dev/null 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"; then
    return 0
  else
    local config_status=$?
    INSTALL_ERROR_CATEGORY="protected_config_validation_failed"
    log "The protected central configuration failed validation against the staged runtime. Module activation was not started."
    return "$config_status"
  fi
}

describe_config_drift() {
  before_path="$1"
  after_path="$2"
  CONFIG_BEFORE="$before_path" CONFIG_AFTER="$after_path" python3 -I - "$INSTALL_BOOTSTRAP_DIR/sls_config_crypto.py" <<'PY' 2>/dev/null || true
import json
import os

import importlib.util
import sys
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("sls_config_crypto", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
before = module.read_config(os.environ["CONFIG_BEFORE"])
after = module.read_config(os.environ["CONFIG_AFTER"])

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
    rollback_failure "protected configuration restoration failed after verification"
    exit 1
  fi
  # Keep the snapshot for the exit guard until rollback is fully verified.
  log "The installer detected an unexpected central config change, restored the original config, and stopped."
  exit 1
}

module_known() {
  fwconsole ma list 2>/dev/null | grep -Eq "\\|[[:space:]]*$1[[:space:]]*\\|"
}

stage_module_directory() {
  if module_known "$MODULE"; then
    HAD_EXISTING_MODULE=1
    [ -e "$CONFIG_FILE" ] || { log "An existing SLS module is missing its protected configuration. Restore a configuration backup before upgrading."; return 1; }
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
    /usr/bin/php -n -l "$script" >/dev/null || {
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
    python3 -I -c 'import pathlib,sys; compile(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"), sys.argv[1], "exec")' "$script" || {
      log "The staged module contains an invalid Python script: $script"
      exit 1
    }
  done < <(find "$STAGING_DIR/$MODULE" -type f -name '*.py' -print)
}

activate_staged_module() {
  MODULE_BACKUP_DIR="$(mktemp -d /tmp/sls-mass-notify-module-backup.XXXXXX)" || return 1
  # Activate by descriptor-relative renames: a replaced public pathname must
  # never redirect privileged copying or ownership changes to another tree.
  /usr/bin/python3 -I - "$STAGING_DIR" "$MODULE_BACKUP_DIR" <<'PYACTIVATE' || return 1
import os, pwd, stat, sys
flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
account = pwd.getpwnam('asterisk')
if account.pw_uid == 0: raise RuntimeError('Asterisk UID must be nonzero')
def directory(path):
    fd = os.open('/', flags)
    try:
        for part in path.split('/'):
            if not part: continue
            if part in ('.', '..'): raise RuntimeError('unsafe module directory')
            child = os.open(part, flags, dir_fd=fd)
            os.close(fd); fd = child
        return fd
    except BaseException:
        os.close(fd); raise
stage, backup, web = (directory(path) for path in (*sys.argv[1:], '/var/www/html/admin/modules'))
try:
    for fd in (stage, backup):
        info = os.fstat(fd)
        if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700: raise RuntimeError('unsafe private module directory')
    tree = os.open('slsmassnotifyserver', flags, dir_fd=stage)
    def prepare(fd):
        for name in os.listdir(fd):
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISDIR(info.st_mode):
                child = os.open(name, flags, dir_fd=fd)
                try: prepare(child)
                finally: os.close(child)
            elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
                try:
                    opened = os.fstat(child)
                    if (info.st_dev,info.st_ino)!=(opened.st_dev,opened.st_ino): raise RuntimeError('staged source changed')
                    os.fchown(child,account.pw_uid,account.pw_gid)
                    os.fchmod(child,0o755 if info.st_mode & 0o111 else 0o644)
                finally: os.close(child)
            else: raise RuntimeError('unsafe staged module entry')
        os.fchown(fd,account.pw_uid,account.pw_gid); os.fchmod(fd,0o755)
    try: prepare(tree)
    finally: os.close(tree)
    prior=False
    try: info=os.stat('slsmassnotifyserver',dir_fd=web,follow_symlinks=False)
    except FileNotFoundError: pass
    else:
        if not stat.S_ISDIR(info.st_mode): raise RuntimeError('installed module directory is unsafe')
        os.rename('slsmassnotifyserver','slsmassnotifyserver',src_dir_fd=web,dst_dir_fd=backup)
        prior=True
    try: os.rename('slsmassnotifyserver','slsmassnotifyserver',src_dir_fd=stage,dst_dir_fd=web)
    except BaseException:
        if prior: os.rename('slsmassnotifyserver','slsmassnotifyserver',src_dir_fd=backup,dst_dir_fd=web)
        raise
    marker = os.open('module-swap-complete', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=backup)
    os.fsync(marker); os.close(marker)
    os.fsync(web); os.fsync(backup)
finally:
    for fd in (stage,backup,web): os.close(fd)
PYACTIVATE
  MODULE_ACTIVATED=1
}

sync_module_version() {
  php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE") ?: "slsmassnotifyserver";
$xmlPath = "/var/www/html/admin/modules/" . $module . "/module.xml";
if (!is_readable($xmlPath)) {
    exit(1);
}
$xml = simplexml_load_file($xmlPath);
$version = $xml && isset($xml->version) ? trim((string)$xml->version) : "";
if ($version === "") {
    exit(1);
}
$db = \FreePBX::Database();
$stmt = $db->prepare("UPDATE modules SET version = ? WHERE modulename = ?");
$stmt->execute([$version, $module]);
exit(0);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

module_registered_at_expected_version() {
  SLS_MASS_NOTIFY_MODULE="$MODULE" php -r '
require "/etc/freepbx.conf";
$module = getenv("SLS_MASS_NOTIFY_MODULE") ?: "slsmassnotifyserver";
$stmt = \FreePBX::Database()->prepare("SELECT version FROM modules WHERE modulename = ? LIMIT 1");
$stmt->execute([$module]);
$version = $stmt->fetchColumn();
exit(is_string($version) && trim($version) === "0.1.5-beta" ? 0 : 1);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

refresh_module_install() {
  log "Refreshing SLS Mass Notify runtime integration."
  if ! SLS_MASS_NOTIFY_DEFER_SIGNING=1 php -r 'require "/etc/freepbx.conf"; require_once "/var/www/html/admin/modules/slsmassnotifyserver/Slsmassnotifyserver.class.php"; $class = "\\FreePBX\\modules\\Slsmassnotifyserver"; $obj = new $class(\FreePBX::Create()); $obj->install(); exit(0);' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "Direct runtime refresh failed. See $LOG_FILE."
    return 1
  fi
}

runtime_install_postconditions_available() {
  local executable
  for executable in \
    /usr/local/bin/slsconsole \
    /usr/local/bin/sls_mass_notify/sls_mass_notify_install_piper_voices.sh \
    /usr/local/bin/sls_mass_notify/sls_notify.py \
    /usr/local/bin/sls_mass_notify/sls_config.py \
    /usr/local/bin/sls_mass_notify/piper/venv/bin/piper \
    /usr/local/bin/piper \
    "$DATA_DIR/piper/venv/bin/piper"; do
    if [ ! -x "$executable" ]; then
      log "Runtime prerequisite is missing or not executable: $executable"
      return 1
    fi
  done
  MODULE_ROOT="/var/www/html/admin/modules/$MODULE" python3 -I - <<'PY'
import filecmp
import os
import pathlib

module = pathlib.Path(os.environ["MODULE_ROOT"])
runtime = pathlib.Path("/usr/local/bin/sls_mass_notify")
expected = {}
for name in (
    "sls_mass_notify_nws_poll.sh",
    "sls_mass_notify_weather_poll.sh",
    "sls_mass_notify_schedule_worker.php",
    "sls_mass_notify_announcement_worker.php",
    "sls_mass_notify_automation_worker.php",
    "sls_mass_notify_cluster_worker.php",
    "sls_mass_notify_cluster_effect.php",
    "sls_mass_notify_delivery_authorization.php",
    "sls_mass_notify_panic.php",
    "sls_mass_notify_live_paging.php",
    "sls_mass_notify_weather_channels.php",
    "sls_mass_notify_phone_agi.py",
    "sls_mass_notify_test.sh",
    "sls_mass_notify_update.sh",
    "sls_mass_notify_maintenance.sh",
    "sls_mass_notify_uninstall.sh",
    "sls_mass_notify_install_piper_voices.sh",
):
    expected[pathlib.PurePosixPath(name)] = module / "bin" / name
source_root = module / "bin" / "sls_mass_notify"
if not source_root.is_dir():
    print("Runtime source directory is missing: bin/sls_mass_notify")
    raise SystemExit(1)
for source in source_root.rglob("*"):
    if source.is_file() and "__pycache__" not in source.parts and source.suffix != ".pyc":
        expected[pathlib.PurePosixPath(source.relative_to(source_root).as_posix())] = source
actual = {
    pathlib.PurePosixPath(path.relative_to(runtime).as_posix())
    for path in runtime.rglob("*")
    if path.is_file()
    and path.relative_to(runtime).parts[:1] != ("piper",)
    and "__pycache__" not in path.parts
    and path.suffix != ".pyc"
}
if actual != set(expected):
    for label, paths in (("missing", set(expected) - actual), ("unexpected", actual - set(expected))):
        for path in sorted(paths)[:12]:
            print(f"Runtime inventory {label}: {str(path)!r}")
    raise SystemExit(1)
for relative, source in expected.items():
    target = runtime / pathlib.Path(str(relative))
    if not target.is_file() or not filecmp.cmp(source, target, shallow=False):
        print(f"Runtime file does not match the installed package: {str(relative)!r}")
        raise SystemExit(1)
PY
}

ensure_runtime_installed() {
  protected_install_phase admit || return 1
  runtime_install_postconditions_available || {
    log "Authenticated runtime postconditions are incomplete. Preserve recovery files; the installer will not execute an older module hook to repair privileged files."
    return 1
  }
}

install_module_with_autoenable() {
  local install_output install_status

  if install_output="$(run_without_install_maintenance_lock fwconsole ma install --autoenable "$MODULE" 2>&1)"; then
    printf '%s\n' "$install_output" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"
    return 0
  else
    install_status=$?
  fi
  printf '%s\n' "$install_output" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}"

  if ! printf '%s\n' "$install_output" | grep -Eqi \
    '(unknown|unrecognized|invalid|no such)[[:space:]-]+option[^[:cntrl:]]*autoenable|option[^[:cntrl:]]*autoenable[^[:cntrl:]]*(does not exist|is not defined|is unknown|is unrecognized)|autoenable[^[:cntrl:]]*option[^[:cntrl:]]*(does not exist|is not defined|is unknown|is unrecognized)'; then
    return "$install_status"
  fi

  log "This fwconsole build requires global options before the module action; retrying compatible --autoenable syntax."
  run_without_install_maintenance_lock fwconsole ma --autoenable install "$MODULE" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

verify_runtime_shell_syntax() {
  local runtime_dir="${1:-/usr/local/bin/sls_mass_notify}"
  local script
  for script in sls_mass_notify_nws_poll.sh sls_mass_notify_weather_poll.sh \
    sls_mass_notify_test.sh sls_mass_notify_update.sh sls_mass_notify_maintenance.sh \
    sls_mass_notify_uninstall.sh sls_mass_notify_install_piper_voices.sh; do
    if [ ! -x "$runtime_dir/$script" ] || ! /bin/bash -n "$runtime_dir/$script" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
      INSTALL_ERROR_CATEGORY="update_script_syntax_error"
      log "Required runtime shell script is missing, not executable, or invalid: $script"
      return 2
    fi
  done
}

verify_announcement_worker_health() {
  local runtime_dir="${1:-/usr/local/bin/sls_mass_notify}"
  /usr/bin/php -l "$runtime_dir/sls_announcement_jobs.php" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || return $?
  /usr/bin/php -l "$runtime_dir/sls_mass_notify_announcement_worker.php" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || return $?
  run_without_install_maintenance_lock /usr/sbin/runuser -u asterisk -- /usr/bin/timeout 45 \
    /usr/bin/php "$runtime_dir/sls_mass_notify_announcement_worker.php" --health-check --record-health >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
}

ensure_piper_runtime() {
  protected_install_phase dependencies --apply
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
      --model "$voice_dir/$model" --output-file "$synthesis_file" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
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
  # Permission repair and promotion are one authenticated, bounded operation.
  # This replaces recursive ownership repair of arbitrary web-owned files.
  protected_install_phase prepare --apply
}

secure_central_config() {
  if ! CONFIG_PATH="$CONFIG_FILE" /usr/bin/python3 -I - <<'PY'
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
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
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
  python3 -I - "$1" <<'PY'
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

verify_ami_with_repair() (
  local attempt probe_dir
  probe_dir="$(mktemp -d /tmp/sls-mass-notify-ami-check.XXXXXX)" || {
    log "Unable to create private AMI verification storage. Check free space and /tmp permissions."
    return 1
  }
  trap 'rm -f -- "$probe_dir/health.json" "$probe_dir/health.err"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  for attempt in 1 2 3; do
    if /usr/bin/timeout 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py --ami-health-json \
      >"$probe_dir/health.json" 2>"$probe_dir/health.err"; then
      if validate_ami_health_file "$probe_dir/health.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1
      then
        return 0
      fi
    fi
    cat "$probe_dir/health.err" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    log "SLS AMI health check attempt $attempt failed; rebuilding the loopback manager integration."
    refresh_module_install >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    asterisk -rx "manager reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
    sleep 2
  done
  log "SLS AMI authentication, Ping, PJSIPShowContacts, or PJSIPNotify authorization failed after automatic repair. See $LOG_FILE."
  return 1
)

validate_phone_collector_probe_file() {
  python3 -I - "$1" "$2" <<'PY'
import json
import math
import sys

kind, path = sys.argv[1:]
with open(path, encoding="utf-8") as handle:
    result = json.load(handle)
if not isinstance(result, dict) or result.get("ok") is not True:
    raise SystemExit("Phone runtime probe failed; review the collector service and local AMI permissions.")
if kind == "ami":
    if result.get("ping") is not True or result.get("show_dialplan") is not True:
        raise SystemExit("AMI Ping/ShowDialPlan authorization was not confirmed; the SLS account requires reporting permission.")
elif kind == "health":
    age = result.get("heartbeat_age_seconds")
    if (result.get("connected") is not True or type(age) not in (int, float)
            or not math.isfinite(age) or not 0 <= age <= 15
            or any(type(result.get(key)) is not int or result[key] < 0 for key in ("active_batches", "reserved_contacts"))):
        raise SystemExit("The phone collector has no valid current heartbeat; new phone audio admission remains blocked.")
else:
    raise SystemExit("Unknown phone runtime probe.")
PY
}

verify_phone_collector() (
  local probe_dir attempt
  systemctl is-enabled --quiet sls-mass-notify-phone-events.service && \
    systemctl is-active --quiet sls-mass-notify-phone-events.service || {
      log "The phone outcome collector must be enabled and active. Review systemctl status sls-mass-notify-phone-events.service, then run Repair Installation."
      return 1
    }
  probe_dir="$(mktemp -d /tmp/sls-phone-collector-check.XXXXXX)" || return 1
  trap 'rm -f -- "$probe_dir/ami.json" "$probe_dir/health.json"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  if ! /usr/bin/timeout --kill-after=1 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I \
      /usr/local/bin/sls_mass_notify/sls_phone_admission.py --probe-ami >"$probe_dir/ami.json" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" \
    || ! validate_phone_collector_probe_file ami "$probe_dir/ami.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log "The SLS phone runtime could not confirm AMI Ping and ShowDialPlan reporting authorization. Review the local AMI connection and run Repair Installation."
    return 1
  fi
  for attempt in 1 2 3; do
    if /usr/bin/timeout --kill-after=1 5 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 -I \
        /usr/local/bin/sls_mass_notify/sls_phone_events.py --health >"$probe_dir/health.json" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" \
      && validate_phone_collector_probe_file health "$probe_dir/health.json" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
      return 0
    fi
    sleep 2
  done
  log "The phone outcome collector has no fresh authenticated heartbeat. New phone audio admission remains blocked; inspect its service journal and private data permissions."
  return 1
)

verify_pjsip_contact_inventory() (
  local probe_dir
  probe_dir="$(mktemp -d /tmp/sls-mass-notify-contact-check.XXXXXX)" || {
    log "Unable to create private PJSIP verification storage. Check free space and /tmp permissions."
    return 1
  }
  trap 'rm -f -- "$probe_dir/contacts.out"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  if ! asterisk -rx "pjsip show contacts" >"$probe_dir/contacts.out" 2>&1; then
    cat "$probe_dir/contacts.out" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    log "Asterisk could not return the PJSIP contact inventory. See $LOG_FILE."
    return 1
  fi
)

verify_local_api_route() (
  local path="$1" expected_pattern="$2" expected_codes="$3" label="$4"
  local probe_dir code
  probe_dir="$(mktemp -d /tmp/sls-mass-notify-api-check.XXXXXX)" || {
    log "Unable to create private API verification storage. Check free space and /tmp permissions."
    return 1
  }
  trap 'rm -f -- "$probe_dir/response.out"; rmdir -- "$probe_dir" 2>/dev/null || true' EXIT
  code="$(local_web_probe "$path" "$probe_dir/response.out" "$expected_pattern" || true)"
  if [[ ! "$code" =~ $expected_pattern ]]; then
    # A failed route may return a login page or PHP exception. Do not retain
    # that potentially sensitive response body in a shared temporary file.
    log "$label expected HTTP $expected_codes for $path, got $code. Check routing on the detected local Apache listener ports and the application error log."
    return 1
  fi
)

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
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    log "Dashboard announcement hook or panel rendering verification failed. See $LOG_FILE."
    exit 1
  }
}

verify_installed_payload_parity() {
  MODULE_ROOT="/var/www/html/admin/modules/$MODULE" python3 -I - <<'PY'
import filecmp
import os
import pathlib
import stat

module = pathlib.Path(os.environ["MODULE_ROOT"])
runtime = pathlib.Path("/usr/local/bin/sls_mass_notify")
expected_runtime = {}
for name in (
    "sls_mass_notify_nws_poll.sh",
    "sls_mass_notify_weather_poll.sh",
    "sls_mass_notify_schedule_worker.php",
    "sls_mass_notify_announcement_worker.php",
    "sls_mass_notify_automation_worker.php",
    "sls_mass_notify_cluster_worker.php",
    "sls_mass_notify_cluster_effect.php",
    "sls_mass_notify_delivery_authorization.php",
    "sls_mass_notify_panic.php",
    "sls_mass_notify_live_paging.php",
    "sls_mass_notify_weather_channels.php",
    "sls_mass_notify_phone_agi.py",
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
    (module / "portal", pathlib.Path("/var/www/html/mass-notify"), "operator portal"),
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
console_source = module / "bin" / "slsconsole"
console_target = pathlib.Path("/usr/local/bin/slsconsole")
console_info = console_target.lstat()
if not stat.S_ISREG(console_info.st_mode) or console_info.st_uid != 0 or console_info.st_nlink != 1 or stat.S_IMODE(console_info.st_mode) != 0o755 or not filecmp.cmp(console_source, console_target, shallow=False):
    raise SystemExit("SLS console differs from its protected packaged source")
PY
}

verify_desktop_sse_handshake() {
  local desktop_status=0
  php -r '
$path = "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config";
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents($path));
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
require_once "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
foreach (\FreePBX\modules\SlsLocalWebProbe::responses(
    (string)($settings["public_pbx_host"] ?? ""),
    "/api/sipnotify/desktop/stream?stream_seconds=1",
    ["Authorization: Basic " . base64_encode($username . ":" . $password), "Accept: text/event-stream"],
    8
) as $response) {
    $headers = implode("\n", (array)$response["headers"]);
    $body = (string)$response["body"];
    if ((int)$response["status"] === 200
        && stripos($headers, "Content-Type: text/event-stream") !== false
        && strpos($body, "event: authenticated") !== false
        && strpos($body, "\"transport\":\"live_sse\"") !== false) {
        exit(0);
    }
}
exit(1);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || desktop_status=$?
  if [ "$desktop_status" -eq 0 ]; then
    log "Desktop live SSE authentication handshake verified."
    return 0
  fi
  if [ "$desktop_status" -eq 3 ]; then
    log "Desktop live SSE handshake skipped because all desktop clients are disabled."
    return 0
  fi
  log "Desktop live SSE authentication failed on the detected local Apache ports. Check virtual-host routing, Authorization-header forwarding, and $LOG_FILE."
  return 1
}

verify_media_access() {
  if ! runuser -u asterisk -- /usr/bin/php -r '
require_once "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
require_once "/var/www/html/api/sls-mass-notify/media-policy.php";
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents("/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config", false, null, 0, 3145729));
if (!is_array($settings)) { fwrite(STDERR, "Media policy cannot read the protected configuration.\n"); exit(1); }
$result = \FreePBX\modules\SlsLocalWebProbe::mediaAccess($settings);
if (!$result["ok"]) { fwrite(STDERR, $result["message"] . "\n"); exit(1); }
' >> "${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
    log 'Generated-media access enforcement could not be verified. Review the preceding policy/Apache routing error before retrying installation.'
    return 1
  fi
}

verify_control_api_authentication() {
  local control_status=0
  php -r '
$path = "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config";
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents($path));
$control = is_array($settings["control_api"] ?? null) ? $settings["control_api"] : [];
if (empty($control["enabled"])) {
    exit(3);
}
$key = trim((string)($control["api_key"] ?? ""));
if ($key === "") {
    exit(1);
}
require_once "/var/www/html/admin/modules/slsmassnotifyserver/LocalWebProbe.php";
$lastError = "";
foreach (\FreePBX\modules\SlsLocalWebProbe::responses(
    (string)($settings["public_pbx_host"] ?? ""),
    "/api/sls-mass-notify/?resource=status",
    ["Authorization: Bearer " . $key, "Accept: application/json"],
    8
) as $response) {
    $decoded = json_decode((string)$response["body"], true);
    if ((int)$response["status"] === 200 && is_array($decoded)
        && !empty($decoded["ok"]) && ($decoded["resource"] ?? "") === "status") {
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
exit($lastError === "unauthorized" ? 2 : 1);
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || control_status=$?
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
  [ -z "$CONFIG_HASH_BEFORE" ] || [ "$FRESH_CONFIG_INITIALIZED" -eq 1 ] || return 0
  CONFIG_PATH="$CONFIG_FILE" SLS_INIT_PHONE_CAPACITY="$FRESH_PHONE_CAPACITY" php -r '
require_once "/usr/local/bin/sls_mass_notify/sls_config_crypto.php";
$settings = \FreePBX\modules\SlsConfigCrypto::decode((string)file_get_contents(getenv("CONFIG_PATH")));
if (!is_array($settings)) {
    exit(1);
}
$setup = is_array($settings["setup"] ?? null) ? $settings["setup"] : [];
$lightning = is_array($settings["xweather"] ?? null) ? $settings["xweather"] : [];
$control = is_array($settings["control_api"] ?? null) ? $settings["control_api"] : [];
$ami = is_array($settings["ami"] ?? null) ? $settings["ami"] : [];
$checks = [
    (int)($settings["phone_device_limit"] ?? 0) === (int)getenv("SLS_INIT_PHONE_CAPACITY"),
    (int)($settings["desktop_client_limit"] ?? 0) === 25,
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
  # The root signer is promoted only from the authenticated immutable generation.
  # Never copy executable bytes out of the web-writable installed module tree.
  protected_install_phase admit
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
      if run_without_install_maintenance_lock /usr/bin/timeout --signal=TERM 360 "$signer" "$module_name" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
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

    SLS_MASS_NOTIFY_MODULE="$module_name" run_without_install_maintenance_lock php -r '
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
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
      log "FreePBX signature verification did not return trusted/good for $module_name. See $LOG_FILE."
      tail -60 "$LOG_FILE" 2>/dev/null || true
      exit 1
    }
  done
}

verify_install() {
  protected_install_phase verify
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
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
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
  if ! printf '%s\n' "$audio_dialplan" | /usr/bin/python3 -I -c 'import sys; text=sys.stdin.read(); prefix="Page(${SLS_DIAL},b(sls-alert-autoanswer^s^1(${EXTEN}))A(${SLS_SAFE_SOUND})inq,"; sys.exit(0 if any(prefix+str(seconds)+")" in text for seconds in range(1,6)) else 1)'; then
    log "The SLS audio paging context is missing its multi-contact Page/ConfBridge auto-answer and audio handler."
    exit 1
  fi
  if printf '%s\n' "$audio_dialplan" | grep -Fq 'Dial(${SLS_DIAL}'; then
    log "The SLS audio paging context still uses first-answer Dial semantics instead of multi-contact Page semantics."
    exit 1
  fi
  if ! printf '%s\n' "$audio_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,origin)' \
    || printf '%s\n' "$audio_dialplan" | grep -Fq 'Set(SLS_DIAL=${PJSIP_DIAL_CONTACTS'; then
    log "The SLS audio context lacks frozen-contact admission or still resolves new contacts after admission."
    exit 1
  fi
  if printf '%s\n' "$audio_dialplan" | grep -Eq 'macro-autoanswer|b\(autoanswer\^'; then
    log "The SLS audio paging context still depends on a FreePBX internal auto-answer context."
    exit 1
  fi
  autoanswer_dialplan="$(asterisk -rx "dialplan show s@sls-alert-autoanswer" 2>&1)"
  outbound_playback_dialplan="$(asterisk -rx "dialplan show s@sls-outbound-playback" 2>&1)"
  if ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,playback)' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'GotoIf($["${SLS_PHONE_ALLOW}"!="1"]?done)' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'Playback(${SLS_SAFE_SOUND})' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'Read(SLS_ACK,${SLS_ACK_PROMPT},1,,1,${SLS_ACK_TIMEOUT})' \
    || ! printf '%s\n' "$outbound_playback_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,ack)'; then
    log "External voice playback lacks its per-recipient answer or keypad-result handling. No external test call was made."
    exit 1
  fi
  printf '%s\n' "$autoanswer_dialplan" | grep -Fq 'AGI(/usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py,contact)' || {
    log "The SLS auto-answer context lacks its exact-contact admission guard."
    exit 1
  }
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
' >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
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
    asterisk -rx "module load res_pjsip_notify.so" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
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
    /usr/local/bin/sls_mass_notify/sls_config_crypto.py
    /usr/local/bin/sls_mass_notify/sls_console.py
    /usr/local/bin/sls_mass_notify/sls_cluster_guard.py
    /usr/local/bin/sls_mass_notify/sls_branded_email.py
    /usr/local/bin/sls_mass_notify/sls_announcement_email.py
    /usr/local/bin/sls_mass_notify/sls_branded_discord.py
    /usr/local/bin/sls_mass_notify/sls_notification_destinations.py
    /usr/local/bin/sls_mass_notify/sls_system_notifications.py
    /usr/local/bin/sls_mass_notify/sls_nws_status.py
    /usr/local/bin/sls_mass_notify/sls_nws_delivery_claims.py
    /usr/local/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py
    /usr/local/bin/sls_mass_notify/sls_weather_queue.py
    /usr/local/bin/sls_mass_notify/sls_audio_queue.py
    /usr/local/bin/sls_mass_notify/sls_audio_state.py
    /usr/local/bin/sls_mass_notify/sls_storage_maintenance.py
    /usr/local/bin/sls_mass_notify/sls_audit_separation.py
    /usr/local/bin/sls_mass_notify/sls_operational_backup.py
    /usr/local/bin/sls_mass_notify/sls_external_monitor.py
    /usr/local/bin/sls_mass_notify/sls_piper_dependencies.py
    /usr/local/bin/sls_mass_notify/sls_piper_environment.py
    /usr/local/bin/sls_mass_notify/sls_update_policy.py
    /usr/local/bin/sls_mass_notify/sls_module_trust.py
    /usr/local/bin/sls_mass_notify/sls_privileged_install.py
    /usr/local/bin/sls_mass_notify/sls_installer_recovery.py
    /usr/local/bin/sls_mass_notify/sls_upgrade_trust.py
    /usr/local/bin/sls_mass_notify/sls_install_idle.py
    /usr/local/bin/sls_mass_notify/sls_install_guard.py
    /usr/local/bin/sls_mass_notify/sls_resource_capacity.py
    /usr/local/bin/sls_mass_notify/sls_phone_admission.py
    /usr/local/bin/sls_mass_notify/sls_phone_events.py
    /usr/local/bin/sls_mass_notify/sls_emergency_observer.py
    /usr/local/bin/sls_mass_notify/sls_trigger_actions.py
    /usr/local/bin/sls_mass_notify/sls_weather_channels.py
    /usr/local/bin/sls_mass_notify/sls_lightning_providers.py
    /usr/local/bin/sls_mass_notify/sls_phone_outcomes.py
    /usr/local/bin/sls_mass_notify/sls_outbound_routes.py
    /usr/local/bin/sls_mass_notify/sls_mass_notify_phone_agi.py
    /usr/local/bin/sls_mass_notify/sls_release_verify.py
    /usr/local/bin/sls_mass_notify/sls_release_trust.py
  )
  for runtime_python in "${runtime_python_files[@]}"; do
    [ -x "$runtime_python" ] || {
      log "Required Python runtime is missing or not executable: $runtime_python"
      exit 1
    }
  done
  python3 -I - "${runtime_python_files[@]}" <<'PY'
import pathlib
import sys

for value in sys.argv[1:]:
    path = pathlib.Path(value)
    compile(path.read_text(encoding="utf-8"), str(path), "exec")
PY
  if /usr/local/bin/sls_mass_notify/sls_config.py "$CONFIG_FILE" >/dev/null; then
    :
  else
    config_status=$?
    INSTALL_ERROR_CATEGORY="protected_config_validation_failed"
    log "The protected central configuration failed validation after runtime integration."
    exit "$config_status"
  fi
  [ ! -e "$DATA_DIR/mass-notifications.conf" ] || {
    log "Obsolete executable shell configuration still exists at $DATA_DIR/mass-notifications.conf."
    exit 1
  }
  [ ! -e /usr/local/bin/sls_mass_notify/config.ini ] || {
    log "Obsolete duplicate Python configuration still exists."
    exit 1
  }
  media_probe="/var/www/html/sls_mass_notify/installer-render-check-$$.png"
  media_fetch="$(mktemp /tmp/sls-mass-notify-render-fetch.XXXXXX)"
  rm -f "$media_probe"
  if ! runuser -u asterisk -- convert -size 480x272 xc:'#991b1b' -font DejaVu-Sans-Bold \
    -fill white -gravity center -pointsize 24 -annotate +0+0 'SLS render test' \
    -colorspace sRGB -depth 8 -interlace none -strip "PNG24:$media_probe"; then
    rm -f "$media_probe" "$media_fetch"
    log "The asterisk service account could not render an alert image in the public media directory."
    exit 1
  fi
  /usr/sbin/apache2ctl configtest >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || {
    rm -f "$media_probe" "$media_fetch"
    log "Apache configuration validation failed after integration was installed. See $LOG_FILE."
    exit 1
  }
  verify_media_access || { rm -f "$media_probe" "$media_fetch"; exit 1; }
  media_code="$(local_web_probe "/sls_mass_notify/$(basename "$media_probe")" "$media_fetch" '^(200|403)$' || true)"
  if [ "$media_code" = "200" ] && cmp -s "$media_probe" "$media_fetch"; then
    :
  elif [ "$media_code" = "403" ] && /usr/bin/python3 -I - "$media_fetch" /usr/local/bin/sls_mass_notify/sls_config_crypto.py <<'PY'
import json
from pathlib import Path
import sys
body = Path(sys.argv[1]).read_bytes()
if len(body) > 1024:
    raise SystemExit(1)
import importlib.util
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('sls_config_crypto', sys.argv[2])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
config = module.read_config('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')
raise SystemExit(0 if config.get('media_access', {}).get('network_restricted') is True
                 and json.loads(body).get('error') == 'media_network_denied' else 1)
PY
  then
    log 'Generated-media policy correctly blocks this local probe. Image bytes are validated locally; test image retrieval from an allowed device network.'
  else
    rm -f "$media_probe" "$media_fetch"
    log "Public phone-image delivery check failed with HTTP $media_code."
    exit 1
  fi
  media_identity="$(identify -format '%w %h %[bit-depth] %[colorspace] %[interlace]' "$media_probe" 2>/dev/null || true)"
  rm -f "$media_probe" "$media_fetch"
  case "$media_identity" in
    "480 272 8 sRGB None"|"480 272 8 RGB None") ;;
    *)
      log "Rendered phone image does not meet the 480x272, 8-bit, non-interlaced requirement: $media_identity"
      exit 1
      ;;
  esac
  log "Verifying Asterisk PJSIP contact inventory and SLS AMI authentication."
  verify_pjsip_contact_inventory || exit 1
  if ! verify_ami_with_repair; then
    exit 1
  fi
  verify_phone_collector || exit 1
  notify_capabilities="$(mktemp /tmp/sls-mass-notify-notify-capabilities.XXXXXX)"
  if ! /usr/bin/timeout 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py --notify-capabilities-json >"$notify_capabilities"; then
    rm -f "$notify_capabilities"
    log "SLS could not inspect Asterisk SIP NOTIFY routing capabilities."
    exit 1
  fi
  notify_routing_mode="$(python3 -I - "$notify_capabilities" <<'PY'
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
    cat "$notify_capabilities" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
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
  if ! /usr/bin/timeout 15 /usr/sbin/runuser -u asterisk -- /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py --list-endpoints-json \
    >"$endpoint_inventory" 2>"$endpoint_inventory_err"; then
    cat "$endpoint_inventory_err" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
    rm -f "$endpoint_inventory" "$endpoint_inventory_err"
    log "SLS AMI PJSIP contact discovery failed. Confirm the loopback manager user has system-event read permission; see $LOG_FILE."
    exit 1
  fi
  endpoint_records=""
  if ! endpoint_records="$(endpoint_inventory_records "$endpoint_inventory" 2>>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}")"; then
    cat "$endpoint_inventory" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>/dev/null || true
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
      *,yealink,*|*,yealink_text,*|*,yealink_image,*)
        yealink_endpoint_list="${yealink_endpoint_list}${yealink_endpoint_list:+,}${extension}"
        ;;
    esac
    if [ "$mixed_format" = "1" ]; then
      if [ "$notify_routing_mode" = "contact_uri" ]; then
        log "Warning: extension $extension has mixed registered phone formats ($formats_csv). Each resolved contact URI will receive its matching vendor payload."
      else
        log "Warning: extension $extension has mixed registered phone formats ($formats_csv), but contact-URI routing is unavailable. Runtime delivery will use one safe generic XML payload through portable endpoint fan-out."
      fi
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
  verify_local_api_route /api/sipnotify/desktop '^(401|429)$' '401/429' 'Desktop notification API smoke test' || exit 1
  verify_local_api_route /api/sipnotify/desktop/stream '^(401|429)$' '401/429' 'Desktop live-stream API without credentials' || exit 1
  verify_control_api_authentication || exit 1
  # Use the canonical directory URL. Apache otherwise returns its expected
  # DirectorySlash 301 before the protected API front controller runs.
  verify_local_api_route /api/sls-mass-notify/ '^(401|403|405|429)$' '401/403/405/429' 'Control API route smoke test' || exit 1
  verify_local_api_route /api/sls-mass-notify/sms-callback.php '^405$' '405' 'SMS callback route without a provider submission' || exit 1
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
  weather_canonical_count="$(printf '%s\n' "$asterisk_cron" | grep -Fxc '* * * * * /usr/bin/timeout 5500 /usr/local/bin/sls_mass_notify/sls_mass_notify_weather_poll.sh' || true)"
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
  if ! runuser -u asterisk -- /usr/bin/timeout 20 /usr/local/bin/sls_mass_notify/sls_mass_notify_schedule_worker.php --self-test >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1; then
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
  if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    printf 'Run this installer as root.\n' >&2
    exit 1
  fi
  ensure_installer_log_prerequisites || exit 1
  INSTALL_LOG_FD=""
  open_root_owned_file INSTALL_LOG_FD "$LOG_FILE" log || {
    printf 'The installer log could not be opened safely: %s. Check for a symlink, hardlink, special file, or unexpected owner. No module files were changed.\n' "$LOG_FILE" >&2
    exit 1
  }
  # Use the held descriptor for every write, including after fwconsole chown.
  # LOG_FILE remains the human-readable path for diagnostics and support.
  INSTALL_LOG_OUTPUT="/proc/${BASHPID}/fd/$INSTALL_LOG_FD"
  : >"$INSTALL_LOG_OUTPUT"
  trap guard_config_on_exit EXIT
  set_install_stage "hardware admission" "Provide the combined PBX/SLS CPU, RAM, persistent storage, and free-space requirements documented in README before retrying installation."
  preflight_hardware_requirements || exit $?
  set_install_stage "platform validation" "Confirm this is a healthy FreePBX 17 host with working database, Asterisk, fwconsole, package repositories, and local AMI access, then rerun the installer."
  require_freepbx
  acquire_update_coordination
  acquire_maintenance_coordination
  set_install_stage "dependency installation" "Restore Debian package repository access and install the prerequisite named in the installer log, then rerun the installer."
  install_dependencies
  preflight_platform
  preflight_python
  ensure_freepbx_prerequisites
  set_install_stage "release download and validation" "Confirm the release URL and checksum, restore GitHub or network access if needed, and rerun the installer."
  download_tgz
  verify_tgz
  stage_module_directory
  prepare_installer_bootstrap
  acquire_worker_coordination
  acquire_settings_coordination
  INSTALL_ERROR_CATEGORY="protected_config_validation_failed"
  snapshot_config
  snapshot_pending_config
  validate_preserved_config_prerequisites
  INSTALL_ERROR_CATEGORY="install_command_failed"
  validate_staged_central_config
  verify_staged_hardware_requirements
  set_install_stage "publisher and stock approval" "Review the upgrade evidence directory printed in the log. Standard stock modules bootstrap from signed upstream packages; local differences require independently reviewed inventories. No maintenance changes occur before approval."
  prepare_authenticated_installer
  if [ "${SLS_MASS_NOTIFY_INVENTORY_ONLY:-0}" = 1 ]; then return 0; fi
  set_install_stage "timezone validation" "Choose a valid IANA timezone listed by timedatectl or repair the host timezone configuration."
  configure_system_timezone
  set_install_stage "protected runtime preparation" "Correct the specific protected-path or dependency error in the installer log; do not run FreePBX module hooks as root."
  STATIC_MUTATION_STARTED=1
  protected_install_phase prepare --apply
  protected_install_phase dependencies --apply
  set_install_stage "module activation" "Review the rejected unprivileged FreePBX integration step in the installer log, then correct that prerequisite before retrying."
  activate_staged_module
  initialize_fresh_install_config
  ensure_local_signer
  if SLS_MASS_NOTIFY_DEFER_SIGNING=1 install_module_with_autoenable; then
    :
  else
    install_status=$?
    log "FreePBX module installation failed (exit $install_status). See $LOG_FILE."
    exit "$install_status"
  fi
  SLS_MASS_NOTIFY_MODULE="$MODULE" sync_module_version
  set_install_stage "runtime integration" "Correct the named integration prerequisite in the installer log; protected operations require the authenticated release and approved stock inventories."
  ensure_runtime_installed
  secure_central_config
  # Fresh installs need the collector running before FreePBX postReload
  # performs readiness checks. Install already wrote the loopback AMI user.
  sls_as_asterisk /usr/sbin/asterisk -rx "manager reload" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  protected_install_phase activate --apply
  SLS_MASS_NOTIFY_PRESERVE_PENDING=1 run_without_install_maintenance_lock fwconsole reload
  protected_install_phase verify
  sls_as_asterisk /usr/sbin/asterisk -rx "module reload res_pjsip_notify.so" >>"${INSTALL_LOG_OUTPUT:-$LOG_FILE}" 2>&1 || true
  sls_as_asterisk /usr/sbin/asterisk -rx "dialplan reload" || true
  set_install_stage "post-install verification" "Review the failed verification in /tmp/slsmassnotifyserver-install.log, correct that PBX-specific prerequisite, and rerun the installer or Repair Installation."
  verify_runtime_shell_syntax
  verify_announcement_worker_health
  verify_piper_voices
  verify_install
  verify_confirmed_system_timezone
  verify_config_unchanged
  verify_pending_config_unchanged
  verify_install_idle_history
  INSTALL_COMMITTED=1
  clear_install_failure
  log "SLS Mass Notify install finished."
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
