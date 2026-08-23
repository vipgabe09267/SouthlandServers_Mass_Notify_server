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
grep -Fq "getattr(os, 'O_NOFOLLOW', 0)" "${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"
grep -Fq 'getattr(os, "O_NOFOLLOW", 0)' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh"

printf 'Installer protected-config regressions passed.\n'
