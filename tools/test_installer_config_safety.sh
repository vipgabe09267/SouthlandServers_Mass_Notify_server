#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "${ROOT_DIR}/tools/install_release.sh"

[ "$(id -u)" -eq 0 ] || {
  printf 'Installer config-safety regression requires root for the production ownership checks.\n' >&2
  exit 1
}

fixture="$(mktemp -d /tmp/sls-installer-config-safety.XXXXXX)"
trap 'find "$fixture" -depth -delete' EXIT
mkdir -p "$fixture/data"
CONFIG_FILE="$fixture/data/mass-notifications.config"
SETTINGS_LOCK="$fixture/data/mass-notifications.config.lock"
snapshot="$fixture/original.snapshot"
sentinel="$fixture/root-sentinel"
printf '%s\n' '{"setup_complete":"1","secret":"preserve-exactly"}' >"$CONFIG_FILE"
printf '%s\n' 'ROOT-SENTINEL-MUST-NOT-CHANGE' >"$sentinel"
chmod 0600 "$sentinel"
install -m 0600 /dev/null "$snapshot"

expected_hash="$(safe_config_snapshot "$CONFIG_FILE" "$snapshot")"
[ "$expected_hash" = "$(safe_config_hash "$CONFIG_FILE")" ]
cmp -s "$CONFIG_FILE" "$snapshot"

rm -f "$CONFIG_FILE"
ln -s "$sentinel" "$CONFIG_FILE"
if safe_config_hash "$CONFIG_FILE" >/dev/null 2>&1; then
  printf 'Installer accepted a symbolic-link protected config.\n' >&2
  exit 1
fi
safe_config_restore "$snapshot" "$CONFIG_FILE"
[ ! -L "$CONFIG_FILE" ]
[ -f "$CONFIG_FILE" ]
cmp -s "$CONFIG_FILE" "$snapshot"
[ "$(cat "$sentinel")" = 'ROOT-SENTINEL-MUST-NOT-CHANGE' ]
[ "$(stat -c '%a %U:%G' "$CONFIG_FILE")" = '640 asterisk:asterisk' ]

rm -f "$CONFIG_FILE"
mkfifo "$CONFIG_FILE"
set +e
/usr/bin/timeout 2 bash -c 'source "$1"; safe_config_hash "$2"' _ \
  "${ROOT_DIR}/tools/install_release.sh" "$CONFIG_FILE" >/dev/null 2>&1
fifo_status=$?
set -e
if [ "$fifo_status" -eq 0 ] || [ "$fifo_status" -eq 124 ]; then
  printf 'Installer accepted or blocked on a FIFO protected config.\n' >&2
  exit 1
fi
rm -f "$CONFIG_FILE"
safe_config_restore "$snapshot" "$CONFIG_FILE"

ln -s "$sentinel" "$SETTINGS_LOCK"
if prepare_settings_lock >/dev/null 2>&1; then
  printf 'Installer accepted a symbolic-link configuration lock.\n' >&2
  exit 1
fi
rm -f "$SETTINGS_LOCK"
prepare_settings_lock
[ ! -L "$SETTINGS_LOCK" ]
[ "$(stat -c '%a %U:%G' "$SETTINGS_LOCK")" = '640 asterisk:asterisk' ]

DATA_DIR="$fixture/data"
INSTALL_FAILURE_FILE="$DATA_DIR/install-failure.json"
FREEPBX_CONFIRMED=1
INSTALL_NOTIFICATION_SIDE_EFFECTS=0
INSTALL_STAGE="config safety regression"
INSTALL_SOLUTION="Run the protected repair verifier."
ln -s "$sentinel" "$INSTALL_FAILURE_FILE"
record_install_failure
[ ! -L "$INSTALL_FAILURE_FILE" ]
python3 - "$INSTALL_FAILURE_FILE" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    payload = json.load(handle)
if payload.get("stage") != "config safety regression":
    raise SystemExit("install-failure marker was not written safely")
PY
[ "$(cat "$sentinel")" = 'ROOT-SENTINEL-MUST-NOT-CHANGE' ]

main_body="$(declare -f main)"
lock_line="$(grep -nFm1 'acquire_settings_coordination' <<<"$main_body" | cut -d: -f1)"
snapshot_line="$(grep -nFm1 'snapshot_config' <<<"$main_body" | cut -d: -f1)"
[ -n "$lock_line" ] && [ -n "$snapshot_line" ] && [ "$lock_line" -lt "$snapshot_line" ]
activation_line="$(grep -nFm1 'install_module_with_autoenable' <<<"$main_body" | cut -d: -f1)"
sync_line="$(grep -nFm1 'sync_module_version' <<<"$main_body" | cut -d: -f1)"
runtime_line="$(grep -nFm1 'ensure_runtime_installed' <<<"$main_body" | cut -d: -f1)"
mapfile -t repair_lines < <(grep -nF 'repair_runtime_permissions' <<<"$main_body" | cut -d: -f1)
pre_activation_repair=0
post_sync_repair=0
for repair_line in "${repair_lines[@]}"; do
  if [ "$repair_line" -lt "$activation_line" ]; then
    pre_activation_repair=1
  fi
  if [ "$repair_line" -gt "$sync_line" ] && [ "$repair_line" -lt "$runtime_line" ]; then
    post_sync_repair=1
  fi
done
[ "$pre_activation_repair" -eq 1 ] || {
  printf 'Installer does not normalize runtime permissions before the FreePBX module hook.\n' >&2
  exit 1
}
[ "$post_sync_repair" -eq 1 ] || {
  printf 'Installer does not normalize runtime permissions after version sync and before runtime refresh.\n' >&2
  exit 1
}
while IFS=: read -r chown_line _; do
  next_command="$(tail -n +$((chown_line + 1)) <<<"$main_body" | sed -n '/[^[:space:]]/{s/^[[:space:]]*//;p;q;}')"
  case "$next_command" in
    repair_runtime_permissions*) ;;
    *)
      printf 'Installer fwconsole chown is not followed immediately by runtime permission repair.\n' >&2
      exit 1
      ;;
  esac
done < <(grep -nE '^[[:space:]]*(/usr/sbin/)?fwconsole chown' <<<"$main_body")

maintenance_script="${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh"
while IFS=: read -r chown_line _; do
  next_command="$(tail -n +$((chown_line + 1)) "$maintenance_script" | sed -n '/[^[:space:]]/{s/^[[:space:]]*//;p;q;}')"
  case "$next_command" in
    repair_runtime_permissions*) ;;
    *)
      printf 'Maintenance fwconsole chown is not followed immediately by runtime permission repair.\n' >&2
      exit 1
      ;;
  esac
done < <(grep -nE '^[[:space:]]*(/usr/sbin/)?fwconsole chown' "$maintenance_script")
maintenance_repair_line="$(grep -nFm1 'repair_runtime_permissions || repair_ok=0' "$maintenance_script" | cut -d: -f1)"
maintenance_install_line="$(grep -nFm1 'new $class' "$maintenance_script" | cut -d: -f1)"
[ -n "$maintenance_repair_line" ] && [ -n "$maintenance_install_line" ] \
  && [ "$maintenance_repair_line" -lt "$maintenance_install_line" ] || {
    printf 'Maintenance does not normalize an existing Piper runtime before module repair.\n' >&2
    exit 1
  }

piper_installer="${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_install_piper_voices.sh"
grep -Fq -- '--repair-permissions-only' "$piper_installer"
grep -Fq 'if [ "$PIPER_ARTIFACTS_CHANGED" -eq 1 ]' "$piper_installer"
grep -Fq "[ -L /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper ]" "$piper_installer"
grep -Fq 'dir_fd=bin_fd, follow_symlinks=False' "$piper_installer"
grep -Fq 'os.open(component, directory_flags, dir_fd=parent_fd)' "$piper_installer"
if grep -Eq 'chown root:root "\$compatibility"|chmod 0755 "\$compatibility"' "$piper_installer"; then
  printf 'Piper compatibility repair reverted to path-based privileged metadata changes.\n' >&2
  exit 1
fi
if grep -Fq 'rm -rf --one-file-system "$compatibility"' "$piper_installer"; then
  printf 'Piper permission repair still recursively deletes its compatibility tree.\n' >&2
  exit 1
fi
if grep -Fq 'chown -R root:root "$PIPER_DIR"' "$piper_installer" \
  || grep -Fq 'find "$PIPER_DIR" -type d' "$piper_installer"; then
  printf 'Piper executable permission repair reverted to recursive pathname operations.\n' >&2
  exit 1
fi
grep -Fq 'PIPER_PERMISSION_ROOT="$PIPER_DIR"' "$piper_installer"
grep -Fq 'SLS_PIPER_VOICE_SOURCE="$tmp"' "$piper_installer"
if grep -Fq 'chown -R asterisk:asterisk "$VOICE_DIR"' "$piper_installer" \
  || grep -Fq 'find "$VOICE_DIR" -type f' "$piper_installer"; then
  printf 'Piper voice permission repair reverted to recursive pathname operations.\n' >&2
  exit 1
fi
grep -Fq 'PIPER_VOICE_PERMISSION_ROOT="$VOICE_DIR"' "$piper_installer"
if grep -Fq 'python3 --version >> "$LOG_FILE"' "$piper_installer"; then
  printf 'No-op Piper verification still appends Python version noise.\n' >&2
  exit 1
fi
grep -Fq "getattr(os, 'O_NOFOLLOW', 0)" "${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"
if grep -Fq "'/bin/chown -R root:root ' . escapeshellarg(self::RUNTIME_DIR)" "${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"; then
  printf 'Module executable-runtime repair reverted to recursive pathname ownership changes.\n' >&2
  exit 1
fi
grep -Fq 'getattr(os, "O_NOFOLLOW", 0)' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh"
grep -Fq 'root_fd = os.open(root, flags)' "${ROOT_DIR}/tools/install_release.sh"
grep -Fq 'root_fd = os.open(root, flags)' "$maintenance_script"
grep -Fq 'runtime entry changed type during repair' "${ROOT_DIR}/tools/install_release.sh"
grep -Fq 'runtime entry changed type during repair' "$maintenance_script"
grep -Fq 'child_relative == "sls_mass_notify_schedule_worker.php"' "${ROOT_DIR}/tools/install_release.sh"
grep -Fq 'child_relative == "sls_mass_notify_schedule_worker.php"' "$maintenance_script"
grep -Fq 'child_relative == "sls_mass_notify_announcement_worker.php"' "${ROOT_DIR}/tools/install_release.sh"
grep -Fq 'child_relative == "sls_mass_notify_announcement_worker.php"' "$maintenance_script"
declare -f runtime_install_postconditions_available | grep -Fq '"sls_mass_notify_announcement_worker.php",'
declare -f verify_installed_payload_parity | grep -Fq '"sls_mass_notify_announcement_worker.php",'
grep -Fq "'sls_weather_queue.py'," "${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"
grep -Fq 'if temporary and bin_fd >= 0:' "$piper_installer"
grep -Fq 'mktemp /usr/local/bin/.sls-piper.XXXXXX' "$piper_installer"
grep -Fq 'close_inherited_maintenance_lock' "${ROOT_DIR}/slsmassnotifyserver/bin/sign_sls_mass_notify_local_sig.sh"
grep -Fq 'run_without_maintenance_lock /usr/bin/timeout 300 /usr/bin/php' "$maintenance_script"
grep -Fq 'run_without_install_maintenance_lock /usr/bin/timeout --signal=TERM 360' "${ROOT_DIR}/tools/install_release.sh"
if grep -Fq 'chown -R root:root /usr/local/bin/sls_mass_notify' "${ROOT_DIR}/tools/install_release.sh" \
  || grep -Fq 'chown -R root:root "$RUNTIME_DIR"' "$maintenance_script"; then
  printf 'Runtime permission repair reverted to a recursive path-following chown.\n' >&2
  exit 1
fi

printf 'Installer protected-config regressions passed.\n'
