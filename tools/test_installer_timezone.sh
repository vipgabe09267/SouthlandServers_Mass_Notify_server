#!/usr/bin/env bash
# shellcheck disable=SC2034
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "${ROOT_DIR}/tools/install_release.sh"

grep -Fq '[ -x /usr/bin/timedatectl ] || missing_packages+=(systemd)' "${ROOT_DIR}/tools/install_release.sh"
grep -Fq '[ -r /usr/share/zoneinfo/UTC ] || missing_packages+=(tzdata)' "${ROOT_DIR}/tools/install_release.sh"

# Detection and validation use systemd's timezone catalogue and the installed
# zoneinfo tree. Malformed names and traversal attempts must never reach
# timedatectl set-timezone.
detected="$(detect_system_timezone)"
timezone_zoneinfo_path "$detected" >/dev/null
validate_system_timezone UTC
for invalid in '' '../UTC' '/etc/passwd' 'America//Chicago' $'UTC\nEtc/UTC' 'Not/A_Real_Zone'; do
  if validate_system_timezone "$invalid"; then
    printf 'Installer accepted an invalid timezone value.\n' >&2
    exit 1
  fi
done

# A noninteractive install must keep the detected timezone and must not read
# stdin or invoke timedatectl set-timezone.
(
  REQUESTED_SYSTEM_TIMEZONE=""
  ORIGINAL_SYSTEM_TIMEZONE=""
  SYSTEM_TIMEZONE_CHANGED=0
  CONFIRMED_SYSTEM_TIMEZONE=""
  set_calls=0
  log() { :; }
  detect_system_timezone() { printf '%s\n' 'America/Chicago'; }
  set_system_timezone() { set_calls=$((set_calls + 1)); }
  configure_system_timezone
  [ "$set_calls" -eq 0 ]
  [ "$ORIGINAL_SYSTEM_TIMEZONE" = 'America/Chicago' ]
  [ "$CONFIRMED_SYSTEM_TIMEZONE" = 'America/Chicago' ]
) </dev/null >/dev/null

# The explicit environment override is validated, applied once, and confirmed
# from a fresh timezone read. This fixture replaces timedatectl and therefore
# cannot change the test host.
(
  REQUESTED_SYSTEM_TIMEZONE="UTC"
  ORIGINAL_SYSTEM_TIMEZONE=""
  SYSTEM_TIMEZONE_CHANGED=0
  CONFIRMED_SYSTEM_TIMEZONE=""
  mock_timezone='America/Chicago'
  set_calls=0
  log() { :; }
  detect_system_timezone() { printf '%s\n' "$mock_timezone"; }
  validate_system_timezone() { [ "$1" = 'UTC' ]; }
  timezone_names_equivalent() { [ "$1" = "$2" ]; }
  set_system_timezone() {
    set_calls=$((set_calls + 1))
    mock_timezone="$1"
  }
  configure_system_timezone
  [ "$set_calls" -eq 1 ]
  [ "$ORIGINAL_SYSTEM_TIMEZONE" = 'America/Chicago' ]
  [ "$SYSTEM_TIMEZONE_CHANGED" -eq 1 ]
  [ "$CONFIRMED_SYSTEM_TIMEZONE" = 'UTC' ]
  [ "$mock_timezone" = 'UTC' ]
) </dev/null >/dev/null

# Final verification detects an unexpected timezone change before installation
# is committed.
(
  CONFIRMED_SYSTEM_TIMEZONE='America/Chicago'
  mock_timezone='America/Chicago'
  log() { :; }
  detect_system_timezone() { printf '%s\n' "$mock_timezone"; }
  timezone_names_equivalent() { [ "$1" = "$2" ]; }
  verify_confirmed_system_timezone
  mock_timezone='UTC'
  if verify_confirmed_system_timezone; then
    printf 'Installer accepted a timezone changed during installation.\n' >&2
    exit 1
  fi
)

# A successful timedatectl call followed by a mismatched readback is treated as
# failure and immediately restores the original timezone.
(
  REQUESTED_SYSTEM_TIMEZONE="UTC"
  ORIGINAL_SYSTEM_TIMEZONE=""
  SYSTEM_TIMEZONE_CHANGED=0
  mock_timezone='America/Chicago'
  set_calls=0
  log() { :; }
  detect_system_timezone() { printf '%s\n' "$mock_timezone"; }
  validate_system_timezone() { [ "$1" = 'UTC' ]; }
  timezone_names_equivalent() { [ "$1" = "$2" ]; }
  set_system_timezone() {
    set_calls=$((set_calls + 1))
    if [ "$1" = 'UTC' ]; then
      mock_timezone='Etc/GMT+1'
    else
      mock_timezone="$1"
    fi
  }
  if configure_system_timezone; then
    printf 'Installer accepted a mismatched timezone readback.\n' >&2
    exit 1
  fi
  [ "$set_calls" -eq 2 ]
  [ "$mock_timezone" = 'America/Chicago' ]
  [ "$SYSTEM_TIMEZONE_CHANGED" -eq 0 ]
) </dev/null >/dev/null

# A rejected override must fail before any timezone-changing command runs.
(
  REQUESTED_SYSTEM_TIMEZONE='../../etc/passwd'
  set_calls=0
  log() { :; }
  detect_system_timezone() { printf '%s\n' 'America/Chicago'; }
  validate_system_timezone() { return 1; }
  set_system_timezone() { set_calls=$((set_calls + 1)); }
  if configure_system_timezone; then
    printf 'Installer accepted a rejected timezone override.\n' >&2
    exit 1
  fi
  [ "$set_calls" -eq 0 ]
) </dev/null >/dev/null

# A later installation failure restores a timezone changed by this run. This
# tests rollback without calling the host's timedatectl.
rollback_log="$(mktemp /tmp/sls-installer-timezone-rollback.XXXXXX)"
trap 'rm -f "$rollback_log"' EXIT
if ROLLBACK_LOG="$rollback_log" bash -c '
  set -euo pipefail
  source "$1"
  MODULE_ACTIVATED=0
  INSTALL_COMMITTED=0
  CONFIG_SNAPSHOT=""
  STAGING_DIR=""
  MODULE_BACKUP_DIR=""
  FREEPBX_CONFIRMED=0
  INSTALL_NOTIFICATION_SIDE_EFFECTS=0
  SYSTEM_TIMEZONE_CHANGED=1
  ORIGINAL_SYSTEM_TIMEZONE="America/Chicago"
  mock_timezone="UTC"
  log() { :; }
  set_system_timezone() {
    printf "%s\n" "$1" >>"$ROLLBACK_LOG"
    mock_timezone="$1"
  }
  detect_system_timezone() { printf "%s\n" "$mock_timezone"; }
  timezone_names_equivalent() { [ "$1" = "$2" ]; }
  set +e
  false
  guard_config_on_exit
' _ "${ROOT_DIR}/tools/install_release.sh"; then
  printf 'Installer failure guard unexpectedly returned success.\n' >&2
  exit 1
fi
[ "$(cat "$rollback_log")" = 'America/Chicago' ]

printf 'Installer timezone checks passed.\n'
