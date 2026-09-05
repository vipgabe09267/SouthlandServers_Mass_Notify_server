#!/usr/bin/env bash
# shellcheck disable=SC2034
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The FreePBX raw module name also forms rollback paths. It is intentionally
# fixed and an environment override must fail before any installer work starts.
if SLS_MASS_NOTIFY_MODULE='../dashboard' \
  bash -c 'source "$1"' _ "${ROOT_DIR}/tools/install_release.sh" \
  >/dev/null 2>&1; then
  printf 'Installer accepted an unsafe alternate module rawname.\n' >&2
  exit 1
fi
if SLS_MASS_NOTIFY_MODULE='othermodule' \
  bash -c 'source "$1"' _ "${ROOT_DIR}/tools/install_release.sh" \
  >/dev/null 2>&1; then
  printf 'Installer accepted a different FreePBX module rawname.\n' >&2
  exit 1
fi
source "${ROOT_DIR}/tools/install_release.sh"

# Prefer the documented action-first --autoenable syntax. Retry the legacy
# global-option ordering only when fwconsole explicitly reports a syntax error;
# a real module installation failure must not be hidden by a second attempt.
(
  fixture="$(mktemp -d /tmp/sls-fwconsole-autoenable.XXXXXX)"
  trap 'find "$fixture" -depth -delete' EXIT
  LOG_FILE="$fixture/install.log"
  calls="$fixture/calls"
  mock_mode="success"
  fwconsole() {
    printf '%s\n' "$*" >>"$calls"
    call_count="$(wc -l <"$calls")"
    if [ "$mock_mode" = "syntax" ] && [ "$call_count" -eq 1 ]; then
      printf '%s\n' 'The --autoenable option does not exist.'
      return 2
    fi
    if [ "$mock_mode" = "failure" ]; then
      printf '%s\n' 'Module installation failed.'
      return 1
    fi
    return 0
  }

  install_module_with_autoenable
  [ "$(sed -n '1p' "$calls")" = "ma install --autoenable slsmassnotifyserver" ]
  [ "$(wc -l <"$calls")" -eq 1 ]

  : >"$calls"
  mock_mode="syntax"
  install_module_with_autoenable
  [ "$(sed -n '1p' "$calls")" = "ma install --autoenable slsmassnotifyserver" ]
  [ "$(sed -n '2p' "$calls")" = "ma --autoenable install slsmassnotifyserver" ]
  [ "$(wc -l <"$calls")" -eq 2 ]

  : >"$calls"
  mock_mode="failure"
  if install_module_with_autoenable; then
    printf 'A genuine FreePBX module failure incorrectly entered the syntax fallback.\n' >&2
    exit 1
  fi
  [ "$(wc -l <"$calls")" -eq 1 ]
)

LOG_FILE="/dev/null"
mock_capability_type="function"
mock_capability_name="PJSIP_HEADER"
mock_available=0
mock_load_success=0
mock_load_calls=0
mock_reload_calls=0
mock_padding_lines=0
mock_heading_case="title"
mock_heading_ansi=1
mock_reported_capability_name=""

log() {
  :
}

# Bootstrap dependencies must be repaired before require_freepbx tries to use
# PHP OpenSSL or the maintenance-lock utilities. A complete PBX must still skip
# apt entirely.
(
  mock_apt_calls=0
  apt-get() {
    mock_apt_calls=$((mock_apt_calls + 1))
    return 1
  }
  install_bootstrap_dependencies
  [ "$mock_apt_calls" -eq 0 ]
)

# Fully provisioned PBXs must not be blocked by an unrelated broken apt source.
# When every concrete capability exists, dependency setup skips apt entirely.
(
  mock_apt_calls=0
  apt-get() {
    mock_apt_calls=$((mock_apt_calls + 1))
    return 1
  }
  install_dependencies
  [ "$mock_apt_calls" -eq 0 ]
)

# Module startup validation accepts autoload or an explicit load, but rejects a
# provider that is merely loaded in the current Asterisk process and noloaded.
(
  mock_modules="$(mktemp /tmp/sls-modules-conf.XXXXXX)"
  trap 'rm -f "$mock_modules"' EXIT
  printf '%s\n' '[modules]' 'autoload=yes' >"$mock_modules"
  asterisk_module_starts_persistently res_pjsip_header_funcs.so "$mock_modules"
  printf '%s\n' '[modules]' 'autoload=yes' 'noload = res_pjsip_header_funcs.so' >"$mock_modules"
  if asterisk_module_starts_persistently res_pjsip_header_funcs.so "$mock_modules"; then
    printf 'A noloaded Asterisk provider was accepted as restart-persistent.\n' >&2
    exit 1
  fi
  printf '%s\n' '[modules]' 'autoload=no' 'load = res_pjsip_header_funcs.so' >"$mock_modules"
  asterisk_module_starts_persistently res_pjsip_header_funcs.so "$mock_modules"
  printf '%s\n' '[modules]' 'autoload=no' 'load => res_pjsip_header_funcs.so' >"$mock_modules"
  asterisk_module_starts_persistently res_pjsip_header_funcs.so "$mock_modules"
  printf '%s\n' '[modules]' 'autoload=yes' 'noload => res_pjsip_header_funcs.so' >"$mock_modules"
  if asterisk_module_starts_persistently res_pjsip_header_funcs.so "$mock_modules"; then
    printf 'An Asterisk-style noload => provider was accepted as restart-persistent.\n' >&2
    exit 1
  fi
)

# The compatibility Piper path is managed only when absent or already SLS-owned.
(
  mock_wrapper_dir="$(mktemp -d /tmp/sls-piper-wrapper.XXXXXX)"
  trap 'find "$mock_wrapper_dir" -depth -delete' EXIT
  mock_wrapper="$mock_wrapper_dir/piper"
  printf '%s\n' '#!/bin/sh' 'exec /opt/other-piper "$@"' >"$mock_wrapper"
  if validate_piper_wrapper_ownership "$mock_wrapper"; then
    printf 'An unrelated Piper wrapper was accepted for overwrite.\n' >&2
    exit 1
  fi
  printf '%s\n' '#!/bin/sh' 'exec /usr/local/bin/sls_mass_notify/piper/venv/bin/piper "$@"' >"$mock_wrapper"
  validate_piper_wrapper_ownership "$mock_wrapper"
)

# Direct installers must exclude the minute maintenance job, while an updater
# child launched by that job must reuse its inherited lock without deadlocking.
(
  mock_lock_dir="$(mktemp -d /tmp/sls-installer-lock.XXXXXX)"
  trap 'find "$mock_lock_dir" -depth -delete' EXIT
  SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$mock_lock_dir/maintenance.lock"
  INSTALL_MAINTENANCE_LOCK_FD=""
  acquire_maintenance_coordination
  [ -n "$INSTALL_MAINTENANCE_LOCK_FD" ]
  if flock -n "$SLS_MASS_NOTIFY_MAINTENANCE_LOCK" -c true; then
    printf 'Installer maintenance lock did not exclude a competing process.\n' >&2
    exit 1
  fi
)
# Commands run inside the transaction must not pass the maintenance descriptor
# to grandchildren such as FreePBX's asynchronous GPG key refresher. The parent
# installer must continue holding the same lock while that command runs.
(
  mock_lock_dir="$(mktemp -d /tmp/sls-installer-child-lock.XXXXXX)"
  trap 'find "$mock_lock_dir" -depth -delete' EXIT
  SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$mock_lock_dir/maintenance.lock"
  child_lock_fd=""
  exec {child_lock_fd}>"$SLS_MASS_NOTIFY_MAINTENANCE_LOCK"
  flock -n "$child_lock_fd"
  run_without_install_maintenance_lock bash -c '
    target="$1"
    for descriptor_path in "/proc/${BASHPID}/fd/"*; do
      [ "$(readlink -f -- "$descriptor_path" 2>/dev/null || true)" != "$target" ] || exit 1
    done
  ' _ "$SLS_MASS_NOTIFY_MAINTENANCE_LOCK"
  if flock -n "$SLS_MASS_NOTIFY_MAINTENANCE_LOCK" -c true; then
    printf 'Child lock cleanup also released the parent installer lock.\n' >&2
    exit 1
  fi
)
(
  mock_lock_dir="$(mktemp -d /tmp/sls-installer-inherited-lock.XXXXXX)"
  trap 'find "$mock_lock_dir" -depth -delete' EXIT
  SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$mock_lock_dir/maintenance.lock"
  INSTALL_MAINTENANCE_LOCK_FD=""
  inherited_maintenance_fd=""
  exec {inherited_maintenance_fd}>"$SLS_MASS_NOTIFY_MAINTENANCE_LOCK"
  flock -n "$inherited_maintenance_fd"
  # The updater opens descriptor 9 for its own lock before it launches the
  # release installer. The maintenance descriptor must remain discoverable.
  exec 9>"$mock_lock_dir/update.lock"
  flock -n 9
  SLS_TEST_INSTALLER="${ROOT_DIR}/tools/install_release.sh" \
  SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$SLS_MASS_NOTIFY_MAINTENANCE_LOCK" \
    bash -c '
      set -euo pipefail
      source "$SLS_TEST_INSTALLER"
      log() { :; }
      INSTALL_MAINTENANCE_LOCK_FD=""
      acquire_maintenance_coordination
      [ -z "$INSTALL_MAINTENANCE_LOCK_FD" ]
    '
)

# An exact-content signer with unsafe ownership/mode must still be repaired
# before the root installer executes it.
(
  mock_signer_dir="$(mktemp -d /tmp/sls-installer-signer.XXXXXX)"
  trap 'find "$mock_signer_dir" -depth -delete' EXIT
  SLS_MASS_NOTIFY_SIGNER_SOURCE="$mock_signer_dir/source.sh"
  SLS_MASS_NOTIFY_SIGNER_TARGET="$mock_signer_dir/target.sh"
  {
    printf '%s\n' '#!/usr/bin/env bash'
    printf '%s\n' 'exit 0'
  } >"$SLS_MASS_NOTIFY_SIGNER_SOURCE"
  cp "$SLS_MASS_NOTIFY_SIGNER_SOURCE" "$SLS_MASS_NOTIFY_SIGNER_TARGET"
  chown www-data:"$(id -gn www-data)" "$SLS_MASS_NOTIFY_SIGNER_TARGET"
  chmod 0777 "$SLS_MASS_NOTIFY_SIGNER_TARGET"
  ensure_local_signer
  [ "$(stat -c '%a %U:%G' "$SLS_MASS_NOTIFY_SIGNER_TARGET")" = "755 root:root" ]
  cmp -s "$SLS_MASS_NOTIFY_SIGNER_SOURCE" "$SLS_MASS_NOTIFY_SIGNER_TARGET"
)

# Exact-version package repair must restore only the package that owns the
# active Asterisk provider path.
(
  mock_repair_dir="$(mktemp -d /tmp/sls-asterisk-package-repair.XXXXXX)"
  trap 'find "$mock_repair_dir" -depth -delete' EXIT
  mock_provider_file="$mock_repair_dir/res_pjsip_header_funcs.so"
  mock_apt_args=()
  REPAIR_ASTERISK_PACKAGES=1
  APT_METADATA_REFRESHED=0
  ASTERISK_PACKAGE_REPAIR_ATTEMPTS=""
  asterisk() {
    case "${2:-}" in
      "core show version")
        printf '%s\n' "Asterisk 22.8.2"
        ;;
      "core show channels count")
        printf '%s\n' "0 active channels"
        ;;
      *)
        return 1
        ;;
    esac
  }
  asterisk_module_file() {
    printf '%s\n' "$mock_provider_file"
  }
  asterisk_provider_package() {
    printf '%s\n' "asterisk22-core"
  }
  refresh_apt_metadata() {
    APT_METADATA_REFRESHED=1
  }
  dpkg-query() {
    if [ "${1:-}" = "-W" ]; then
      printf '%s\n' "22.8.2-1.sng12"
      return 0
    fi
    return 1
  }
  apt-get() {
    mock_apt_args=("$@")
    : >"$mock_provider_file"
  }
  wait_for_asterisk_cli() {
    return 0
  }
  repair_asterisk_provider_package res_pjsip_header_funcs.so
  [ -f "$mock_provider_file" ]
  [ "${#mock_apt_args[@]}" -eq 6 ]
  [ "${mock_apt_args[0]}" = "install" ]
  [ "${mock_apt_args[1]}" = "-y" ]
  [ "${mock_apt_args[2]}" = "--reinstall" ]
  [ "${mock_apt_args[3]}" = "--no-install-recommends" ]
  [ "${mock_apt_args[4]}" = "--no-remove" ]
  [ "${mock_apt_args[5]}" = "asterisk22-core=22.8.2-1.sng12" ]
  [ "$APT_METADATA_REFRESHED" -eq 1 ]
)

# Missing provider files remain attributable through dpkg's package database.
# Canonical /lib and /usr/lib paths and multiarch package names are accepted,
# while unrelated same-basename results are ignored.
(
  mock_provider_file="/lib/x86_64-linux-gnu/asterisk/modules/sls-test-provider.so"
  canonical_provider="$(readlink -m -- "$mock_provider_file")"
  dpkg-query() {
    case "${1:-}" in
      -S)
        printf '%s\n' "unrelated-provider: /opt/asterisk/modules/sls-test-provider.so"
        printf '%s: %s\n' "asterisk22-core:amd64" "$canonical_provider"
        ;;
      -W)
        [ "${3:-}" = "asterisk22-core:amd64" ] || return 1
        printf '%s\n' "ii "
        ;;
      *)
        return 1
        ;;
    esac
  }
  [ "$(asterisk_provider_package "$mock_provider_file")" = "asterisk22-core:amd64" ]
)

# Repair is opt-out and must stop before querying or changing packages.
(
  REPAIR_ASTERISK_PACKAGES=0
  if repair_asterisk_provider_package res_pjsip_header_funcs.so; then
    printf 'Disabled Asterisk package repair was incorrectly attempted.\n' >&2
    exit 1
  fi
)

# A package repair must never begin while active calls could be disrupted.
(
  REPAIR_ASTERISK_PACKAGES=1
  mock_provider_lookup_calls=0
  mock_channel_count_output="2 active channels"
  asterisk() {
    case "${2:-}" in
      "core show version")
        printf '%s\n' "Asterisk 22.8.2"
        ;;
      "core show channels count")
        printf '%s\n' "$mock_channel_count_output"
        ;;
      *)
        return 1
        ;;
    esac
  }
  asterisk_module_file() {
    mock_provider_lookup_calls=$((mock_provider_lookup_calls + 1))
  }
  if repair_asterisk_provider_package res_pjsip_header_funcs.so; then
    printf 'Asterisk package repair ignored active calls.\n' >&2
    exit 1
  fi
  [ "$mock_provider_lookup_calls" -eq 0 ]
  mock_channel_count_output="Unable to query channel count"
  if repair_asterisk_provider_package res_pjsip_header_funcs.so; then
    printf 'Asterisk package repair accepted an unknown channel count.\n' >&2
    exit 1
  fi
  [ "$mock_provider_lookup_calls" -eq 0 ]
)

mock_package_repair_success=0
mock_package_repair_calls=0
repair_asterisk_provider_package() {
  mock_package_repair_calls=$((mock_package_repair_calls + 1))
  if [ "$mock_package_repair_success" -eq 1 ]; then
    mock_load_success=1
    return 0
  fi
  return 1
}

asterisk() {
  local command line heading_name
  [ "${1:-}" = "-rx" ] || return 1
  command="${2:-}"
  heading_name="${mock_reported_capability_name:-$mock_capability_name}"
  case "$command" in
    "core show function ${mock_capability_name}")
      if [ "$mock_capability_type" = "function" ] && [ "$mock_available" -eq 1 ]; then
        if [ "$mock_heading_case" = "lower" ]; then
          if [ "$mock_heading_ansi" -eq 1 ]; then
            printf "\033[1;35m  -= Info about function '%s' =- \033[0m\n" "$heading_name"
          else
            printf "  -= Info about function '%s' =-\n" "$heading_name"
          fi
        elif [ "$mock_heading_ansi" -eq 1 ]; then
          printf "\033[1;35m  -= Info about Function '%s' =- \033[0m\n" "$heading_name"
        else
          printf "  -= Info about Function '%s' =-\n" "$heading_name"
        fi
        for ((line = 0; line < mock_padding_lines; line++)); do
          printf 'Long Asterisk function documentation line %05d\n' "$line"
        done
      else
        printf "No function by that name registered.\n"
      fi
      ;;
    "core show application ${mock_capability_name}")
      if [ "$mock_capability_type" = "application" ] && [ "$mock_available" -eq 1 ]; then
        if [ "$mock_heading_case" = "lower" ]; then
          if [ "$mock_heading_ansi" -eq 1 ]; then
            printf "\033[1;35m  -= Info about application '%s' =- \033[0m\n" "$heading_name"
          else
            printf "  -= Info about application '%s' =-\n" "$heading_name"
          fi
        elif [ "$mock_heading_ansi" -eq 1 ]; then
          printf "\033[1;35m  -= Info about Application '%s' =- \033[0m\n" "$heading_name"
        else
          printf "  -= Info about Application '%s' =-\n" "$heading_name"
        fi
      else
        printf "Your application(s) is (are) not registered.\n"
      fi
      ;;
    "module load "*)
      mock_load_calls=$((mock_load_calls + 1))
      if [ "$mock_load_success" -eq 1 ]; then
        mock_available=1
      fi
      # Asterisk can return zero even when the CLI text reports failure.
      return 0
      ;;
    "module reload "*)
      mock_reload_calls=$((mock_reload_calls + 1))
      if [ "$mock_load_success" -eq 1 ]; then
        mock_available=1
      fi
      return 0
      ;;
    "core show settings")
      printf "  Module directory: /tmp/sls-missing-asterisk-modules\n"
      ;;
    *)
      printf 'Unexpected mocked Asterisk command: %s\n' "$command" >&2
      return 1
      ;;
  esac
}

# An already registered capability must not trigger a load.
mock_available=1
mock_load_success=0
mock_load_calls=0
mock_reload_calls=0
ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so
[ "$mock_load_calls" -eq 0 ]
[ "$mock_reload_calls" -eq 0 ]

# Lowercase headings used by downstream/community Asterisk builds are the same
# registered capability and must not trigger module load or package repair.
mock_heading_case="lower"
mock_heading_ansi=0
mock_package_repair_calls=0
ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so
[ "$mock_load_calls" -eq 0 ]
[ "$mock_reload_calls" -eq 0 ]
[ "$mock_package_repair_calls" -eq 0 ]

# Case-insensitivity must not weaken the exact quoted capability-name match.
mock_reported_capability_name="PJSIP_HEADER_EXTRA"
if asterisk_capability_available function PJSIP_HEADER; then
  printf 'A similarly named Asterisk function was incorrectly accepted.\n' >&2
  exit 1
fi
mock_reported_capability_name=""

# Generic missing-capability output must remain a failure.
mock_available=0
if asterisk_capability_available function PJSIP_HEADER; then
  printf 'Missing-capability output was incorrectly accepted.\n' >&2
  exit 1
fi

# Both title-case and lowercase application headings remain valid, including
# ANSI-colored community output.
mock_capability_type="application"
mock_capability_name="Dial"
mock_available=1
mock_heading_case="lower"
mock_heading_ansi=1
asterisk_capability_available application Dial
mock_heading_case="title"
mock_heading_ansi=0
asterisk_capability_available application Dial

mock_capability_type="function"
mock_capability_name="PJSIP_HEADER"
mock_available=1
mock_heading_case="title"
mock_heading_ansi=1

# Long help output must not become a false negative under shell pipefail.
mock_padding_lines=6000
asterisk_capability_available function PJSIP_HEADER
mock_padding_lines=0

# An installed but unloaded provider must be loaded and then accepted.
mock_available=0
mock_load_success=1
mock_load_calls=0
mock_reload_calls=0
ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so
[ "$mock_available" -eq 1 ]
[ "$mock_load_calls" -eq 1 ]
[ "$mock_reload_calls" -eq 0 ]

# A deceptive zero CLI status must not hide a failed module load.
mock_available=0
mock_load_success=0
mock_load_calls=0
mock_reload_calls=0
mock_package_repair_success=0
mock_package_repair_calls=0
if ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so; then
  printf 'A missing PJSIP_HEADER capability was incorrectly accepted.\n' >&2
  exit 1
fi
[ "$mock_load_calls" -eq 1 ]
[ "$mock_reload_calls" -eq 1 ]
[ "$mock_package_repair_calls" -eq 1 ]

# A failed initial load must retry successfully after exact package repair.
mock_available=0
mock_load_success=0
mock_load_calls=0
mock_reload_calls=0
mock_package_repair_success=1
mock_package_repair_calls=0
ensure_asterisk_capability function PJSIP_HEADER res_pjsip_header_funcs.so
[ "$mock_available" -eq 1 ]
[ "$mock_load_calls" -eq 2 ]
[ "$mock_reload_calls" -eq 1 ]
[ "$mock_package_repair_calls" -eq 1 ]
mock_package_repair_success=0

# Required modules use the same deceptive-status-resistant load, repair, and
# post-repair verification behavior as individual dialplan capabilities.
(
  mock_module_running=0
  mock_module_load_success=0
  mock_module_load_calls=0
  mock_module_repair_calls=0
  asterisk() {
    case "${2:-}" in
      "module show like res_pjsip_notify.so")
        if [ "$mock_module_running" -eq 1 ]; then
          printf '%s\n' "res_pjsip_notify.so PJSIP notify support 0 Running core"
        else
          printf '%s\n' "0 modules loaded"
        fi
        ;;
      "module load res_pjsip_notify.so")
        mock_module_load_calls=$((mock_module_load_calls + 1))
        if [ "$mock_module_load_success" -eq 1 ]; then
          mock_module_running=1
        fi
        return 0
        ;;
      *)
        return 1
        ;;
    esac
  }
  repair_asterisk_provider_package() {
    mock_module_repair_calls=$((mock_module_repair_calls + 1))
    mock_module_load_success=1
  }
  ensure_asterisk_module_loaded res_pjsip_notify.so
  [ "$mock_module_running" -eq 1 ]
  [ "$mock_module_load_calls" -eq 2 ]
  [ "$mock_module_repair_calls" -eq 1 ]
)

# Local channels are built into supported Asterisk releases and therefore must
# be verified as a channel driver rather than assumed to be chan_local.so.
(
  asterisk() {
    if [ "${2:-}" = "core show channeltype Local" ]; then
      printf '\033[1;35m%s\033[0m\n' "-- info about CHANNEL DRIVER: local --"
      return 0
    fi
    printf '%s\n' "No such channel type."
  }
  asterisk_channel_type_available Local
  if asterisk_channel_type_available Missing; then
    printf 'A missing Asterisk channel driver was incorrectly accepted.\n' >&2
    exit 1
  fi
)

# Applications use the same load-and-recheck behavior.
mock_capability_type="application"
mock_capability_name="Dial"
mock_available=0
mock_load_success=1
mock_load_calls=0
mock_reload_calls=0
mock_heading_case="lower"
mock_heading_ansi=1
ensure_asterisk_capability application Dial app_dial.so
[ "$mock_available" -eq 1 ]
[ "$mock_load_calls" -eq 1 ]
mock_heading_case="title"

# Settings and module parsing must tolerate case and ANSI differences while
# still requiring the active Asterisk directories supported by this release.
(
  mock_spool="/var/spool/asterisk"
  mock_varlib="/var/lib/asterisk"
  mock_data="/var/lib/asterisk"
  asterisk() {
    if [ "${2:-}" = "core show settings" ]; then
      printf '\033[1;35m  module DIRECTORY:\033[0m /lib/asterisk/modules\n'
      printf '  spool DIRECTORY: %s\n' "$mock_spool"
      printf '  VARLIB directory: %s\n' "$mock_varlib"
      printf '  data directory: %s\n' "$mock_data"
      return 0
    fi
    return 1
  }
  [ "$(asterisk_setting_value "Module directory")" = "/lib/asterisk/modules" ]
  [ "$(asterisk_module_file "test-provider.so")" = "/lib/asterisk/modules/test-provider.so" ]
  verify_supported_asterisk_directories
  mock_spool="/srv/asterisk/spool"
  if verify_supported_asterisk_directories; then
    printf 'An unsupported live Asterisk spool path was incorrectly accepted.\n' >&2
    exit 1
  fi
  mock_spool="/var/spool/asterisk"
  mock_varlib="/srv/asterisk/lib"
  if verify_supported_asterisk_directories; then
    printf 'An unsupported live Asterisk VarLib path was incorrectly accepted.\n' >&2
    exit 1
  fi
  mock_varlib="/var/lib/asterisk"
  mock_data="/srv/asterisk/data"
  if verify_supported_asterisk_directories; then
    printf 'An unsupported live Asterisk data path was incorrectly accepted.\n' >&2
    exit 1
  fi
  mock_data=""
  verify_supported_asterisk_directories
  mock_varlib=""
  if verify_supported_asterisk_directories; then
    printf 'A missing live Asterisk VarLib path was incorrectly accepted.\n' >&2
    exit 1
  fi
)

# Module status parsing must accept colored/lowercase community output but not
# a matching module that is explicitly Not Running.
(
  mock_status="running"
  asterisk() {
    if [ "${2:-}" = "module show like res_pjsip_notify.so" ]; then
      printf '\033[1;32mres_pjsip_notify.so\033[0m notify 0 %s core\n' "$mock_status"
      return 0
    fi
    return 1
  }
  asterisk_module_loaded res_pjsip_notify.so
  mock_status="Not Running"
  if asterisk_module_loaded res_pjsip_notify.so; then
    printf 'A Not Running Asterisk module was incorrectly accepted.\n' >&2
    exit 1
  fi
)

# Wait is built into supported Asterisk builds. A missing built-in capability
# must fail without attempting a provider load or package repair.
(
  mock_provider_activity=0
  asterisk() {
    if [ "${2:-}" = "core show application Wait" ]; then
      printf '%s\n' "Your application(s) is (are) not registered."
      return 0
    fi
    mock_provider_activity=$((mock_provider_activity + 1))
    return 1
  }
  repair_asterisk_provider_package() {
    mock_provider_activity=$((mock_provider_activity + 1))
    return 1
  }
  if require_asterisk_capability application Wait; then
    printf 'A missing built-in Wait application was incorrectly accepted.\n' >&2
    exit 1
  fi
  [ "$mock_provider_activity" -eq 0 ]
)

# Effective paging routes must remain entirely PJSIP. The installer reports a
# legacy AstDB route instead of rewriting it, and only accepts endpoint fallback
# when the corresponding PJSIP endpoint object actually exists.
pjsip_dial_string_supported 'PJSIP/1000'
pjsip_dial_string_supported 'PJSIP/1000/sip:1000@192.0.2.10&PJSIP/1000/sip:1000@192.0.2.11'
if pjsip_dial_string_supported 'Local/1000@from-internal'; then
  printf 'A Local paging route was incorrectly accepted as PJSIP.\n' >&2
  exit 1
fi
if pjsip_dial_string_supported 'PJSIP/1000&SIP/1000'; then
  printf 'A mixed PJSIP/legacy route was incorrectly accepted.\n' >&2
  exit 1
fi
(
  mock_db_route="PJSIP/1000"
  mock_dynamic_route="PJSIP/1000/sip:1000@192.0.2.10"
  mock_aor_route=""
  asterisk() {
    case "${2:-}" in
      "database get DEVICE 1000/dial")
        if [ -n "$mock_db_route" ]; then
          printf '\033[1;32mVALUE:\033[0m %s\n' "$mock_db_route"
        else
          printf '%s\n' "Database entry not found."
        fi
        ;;
      "dialplan eval function PJSIP_DIAL_CONTACTS(1000)")
        printf '%s\n' "Return Value: Success (0)"
        [ -z "$mock_dynamic_route" ] || printf 'RESULT: %s\n' "$mock_dynamic_route"
        ;;
      "dialplan eval function PJSIP_DIAL_CONTACTS(custom-aor)")
        printf '%s\n' "Return Value: Success (0)"
        [ -z "$mock_aor_route" ] || printf 'RESULT: %s\n' "$mock_aor_route"
        ;;
      "pjsip show endpoint 1000")
        if [ "${mock_endpoint_available:-1}" -eq 1 ]; then
          printf '%s\n' ' Endpoint:  1000/1000                         Not in use    0 of inf'
        else
          printf '%s\n' 'Unable to find object 1000.'
        fi
        ;;
      *) return 1 ;;
    esac
  }
  verify_registered_endpoint_route 1000
  mock_db_route="Local/1000@from-internal"
  verify_registered_endpoint_route 1000
  mock_dynamic_route=""
  if verify_registered_endpoint_route 1000; then
    printf 'A non-PJSIP AstDB fallback was accepted without explicit PJSIP contacts.\n' >&2
    exit 1
  fi
  mock_db_route="PJSIP/custom-aor"
  mock_aor_route="PJSIP/1000/sip:1000@192.0.2.12"
  verify_registered_endpoint_route 1000
  mock_db_route=""
  mock_aor_route=""
  mock_dynamic_route="PJSIP/9999/sip:9999@192.0.2.20"
  if verify_registered_endpoint_route 1000; then
    printf 'A paging route referencing a missing PJSIP endpoint was incorrectly accepted.\n' >&2
    exit 1
  fi
  mock_dynamic_route=""
  verify_registered_endpoint_route 1000
  mock_endpoint_available=0
  if verify_registered_endpoint_route 1000; then
    printf 'A fabricated PJSIP endpoint fallback was incorrectly accepted.\n' >&2
    exit 1
  fi
)

# AMI endpoint records are validated independently of `pjsip show contacts`
# summary wording. Empty inventories remain valid and unknown formats are
# surfaced explicitly to the caller.
(
  inventory_dir="$(mktemp -d /tmp/sls-installer-inventory.XXXXXX)"
  trap 'find "$inventory_dir" -depth -delete' EXIT
  printf '%s\n' '{}' >"$inventory_dir/empty.json"
  [ -z "$(endpoint_inventory_records "$inventory_dir/empty.json")" ]
  printf '%s\n' '{"1000":{"contacts":2,"format":"unknown","formats":["unknown"],"user_agent":"Community Phone"}}' >"$inventory_dir/unknown.json"
  [ "$(endpoint_inventory_records "$inventory_dir/unknown.json")" = $'1000\tunknown\t2\t1\t0\tunknown\tCommunity Phone' ]
  printf '%s\n' '{"1000":{"contacts":2,"format":"yealink","formats":["yealink","poly"],"user_agent":"Yealink | Poly"}}' >"$inventory_dir/mixed.json"
  [ "$(endpoint_inventory_records "$inventory_dir/mixed.json")" = $'1000\tyealink\t2\t0\t1\tpoly,yealink\tYealink | Poly' ]
  printf '%s\n' '{"1000":{"contacts":1,"format":"unsupported","formats":["unsupported"]}}' >"$inventory_dir/invalid.json"
  if endpoint_inventory_records "$inventory_dir/invalid.json" >/dev/null 2>&1; then
    printf 'An unsupported endpoint inventory format was incorrectly accepted.\n' >&2
    exit 1
  fi
)

# Mixed-vendor registrations prefer contact-URI routing, but endpoint-fanout
# Asterisk remains supported through the safe generic XML runtime fallback.
mixed_endpoint_visual_route_supported 0 endpoint_fanout
mixed_endpoint_visual_route_supported 0 contact_uri
mixed_endpoint_visual_route_supported 1 contact_uri
mixed_endpoint_visual_route_supported 1 endpoint_fanout

# Regression guard: AMI discovery is unconditional and no longer branches on
# the display-only "Objects found" footer emitted by selected Asterisk builds.
if grep -Fq "if grep -Eq 'Objects found" "${ROOT_DIR}/tools/install_release.sh"; then
  printf 'Installer still branches AMI discovery on Asterisk summary text.\n' >&2
  exit 1
fi
[ "$(grep -Fc -- '--list-endpoints-json' "${ROOT_DIR}/tools/install_release.sh")" -ge 1 ]

# Regression guards for dependency-bootstrap ordering and the exact image
# validation tools used by sls_notify.py and verify_install().
installer_source="${ROOT_DIR}/tools/install_release.sh"
class_dependency_source="${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"
module_xml_source="${ROOT_DIR}/slsmassnotifyserver/module.xml"
grep -Fq '<module>backup ge 17.0.0</module>' "$module_xml_source"
grep -Fq 'for prerequisite in framework dashboard backup recordings; do' "$installer_source"
grep -Fq 'for required_module in framework dashboard backup recordings; do' "$installer_source"
grep -Fq 'FreePBX did not discover the Mass Notify native backup adapter.' "$installer_source"
grep -Fq 'Native FreePBX backup adapter verified; this PBX has no administrator-defined module backup jobs yet.' "$installer_source"
require_body="$(declare -f require_freepbx)"
bootstrap_call_line="$(grep -nFm1 'install_bootstrap_dependencies' <<<"$require_body" | cut -d: -f1)"
bootstrap_utility_line="$(grep -nFm1 'for bootstrap_utility in /usr/bin/flock' <<<"$require_body" | cut -d: -f1)"
[ -n "$bootstrap_call_line" ]
[ -n "$bootstrap_utility_line" ]
[ "$bootstrap_call_line" -lt "$bootstrap_utility_line" ]

main_body="$(declare -f main)"
log_reset_line="$(grep -nFm1 ': > "$LOG_FILE"' <<<"$main_body" | cut -d: -f1)"
failure_trap_line="$(grep -nFm1 'trap guard_config_on_exit EXIT' <<<"$main_body" | cut -d: -f1)"
require_line="$(grep -nFm1 'require_freepbx' <<<"$main_body" | cut -d: -f1)"
lock_line="$(grep -nFm1 'acquire_maintenance_coordination' <<<"$main_body" | cut -d: -f1)"
dependency_line="$(grep -nFm1 'install_dependencies' <<<"$main_body" | cut -d: -f1)"
[ "$log_reset_line" -lt "$require_line" ]
[ "$failure_trap_line" -lt "$require_line" ]
[ "$require_line" -lt "$lock_line" ]
[ "$lock_line" -lt "$dependency_line" ]
guard_body="$(declare -f guard_config_on_exit)"
grep -Fq 'record_install_failure' <<<"$guard_body"
grep -Fq 'clear_install_failure' <<<"$main_body"
grep -Fq 'INSTALL_FAILURE_FILE="$DATA_DIR/install-failure.json"' "$installer_source"
grep -Fq 'add_error(' "$installer_source"
grep -Fq "const INSTALL_FAILURE_JSON = self::PLUGIN_DATA_DIR . '/install-failure.json';" "$class_dependency_source"
grep -Fq 'The last installation or repair failed during %s. Possible solution: %s' "$class_dependency_source"
maintenance_source="${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_maintenance.sh"
repair_verify_line="$(grep -nFm1 'verifyProtectedRepairIntegration' "$maintenance_source" | cut -d: -f1)"
repair_clear_line="$(grep -nF 'clear_install_failure' "$maintenance_source" | tail -n1 | cut -d: -f1)"
[ -n "$repair_verify_line" ]
[ -n "$repair_clear_line" ]
[ "$repair_verify_line" -lt "$repair_clear_line" ]
grep -Fq 'public function verifyProtectedRepairIntegration()' "$class_dependency_source"
grep -Fq "foreach (['slsmassnotifyserver', 'dashboard', 'framework'] as \$module)" "$class_dependency_source"
grep -Fq -- "--list-endpoints-json 2>/dev/null" "$class_dependency_source"
class_install_body="$(sed -n '/public function install()/,/public function uninstall()/p' "$class_dependency_source")"
if grep -Fq 'clearSuccessfulInstallFailureState' <<<"$class_install_body"; then
  printf 'Module install clears protected failure state before comprehensive verification.\n' >&2
  exit 1
fi
grep -Fq 'Healthy. Last Asterisk submission queued:' "$class_dependency_source"

grep -Fq '{ [ -x /usr/bin/convert ] && [ -x /usr/bin/identify ]; } || add_missing_package imagemagick' "$installer_source"
grep -Fq 'for package in /usr/bin/curl /usr/bin/wget /usr/bin/gpg /usr/bin/python3 /usr/bin/sox /usr/bin/soxi /usr/bin/convert /usr/bin/identify ' "$installer_source"
grep -Fq '[ -r /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf ] || {' "$installer_source"
grep -Fq 'function_exists("openssl_encrypt") && function_exists("openssl_decrypt")' "$installer_source"
class_dependency_source="${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"
grep -Fq "'/usr/bin/identify'" "$class_dependency_source"
grep -Fq "!is_readable('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf')" "$class_dependency_source"
grep -Fq "!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')" "$class_dependency_source"

# The outbound called channel's contact remains the preferred Asterisk 22
# user-agent source. The explicit extension/AOR fallback protects compatible
# community builds where CHANNEL(contact) is empty in the pre-dial handler.
class_source="${ROOT_DIR}/slsmassnotifyserver/Slsmassnotifyserver.class.php"
channel_contact_line="$(grep -nFm1 'Set(SLS_AUTOANSWER_CONTACT=\${CHANNEL(contact)})' "$class_source" | cut -d: -f1)"
aor_fallback_line="$(grep -nFm1 'PJSIP_AOR(\${SLS_AUTOANSWER_AOR},contact)' "$class_source" | cut -d: -f1)"
[ -n "$channel_contact_line" ]
[ -n "$aor_fallback_line" ]
[ "$channel_contact_line" -lt "$aor_fallback_line" ]
grep -Fq 'PJSIP_CONTACT(\${SLS_AUTOANSWER_CONTACT},user_agent)' "$class_source"
grep -Fq '\${SLS_AUTOANSWER_UA:0:7}\"=\"yealink\"]?Set(SLS_ALERT_INFO=Intercom)' "$class_source"
grep -Fq 'Page(\${SLS_DIAL},b(sls-alert-autoanswer^s^1(\${EXTEN}))A(\${SLS_SAFE_SOUND})inq,{$pagingAnswerTimeout})' "$class_source"
if grep -Fq 'Dial(\${SLS_DIAL}' "$class_source"; then
  printf 'Managed paging still uses first-answer Dial semantics.\n' >&2
  exit 1
fi
dynamic_contacts_line="$(grep -nFm1 'Set(SLS_DIAL=\${PJSIP_DIAL_CONTACTS(\${EXTEN})})' "$class_source" | cut -d: -f1)"
device_lookup_line="$(grep -nFm1 'Set(SLS_DEVICE_DIAL=\${DB(DEVICE/\${EXTEN}/dial)})' "$class_source" | cut -d: -f1)"
device_aor_line="$(grep -nFm1 'Set(SLS_DEVICE_AOR=\${CUT(SLS_DEVICE_DIAL,/,2)})' "$class_source" | cut -d: -f1)"
device_aor_default_line="$(grep -nFm1 'Set(SLS_DEVICE_AOR=\${EXTEN})' "$class_source" | cut -d: -f1)"
device_contacts_line="$(grep -nFm1 'Set(SLS_DIAL=\${PJSIP_DIAL_CONTACTS(\${SLS_DEVICE_AOR})})' "$class_source" | cut -d: -f1)"
device_fallback_line="$(grep -nFm1 'Set(SLS_DIAL=\${SLS_DEVICE_DIAL})' "$class_source" | cut -d: -f1)"
[ "$dynamic_contacts_line" -lt "$device_lookup_line" ]
[ "$device_lookup_line" -lt "$device_aor_line" ]
[ "$device_aor_line" -lt "$device_aor_default_line" ]
[ "$device_aor_default_line" -lt "$device_contacts_line" ]
[ "$device_contacts_line" -lt "$device_fallback_line" ]
[ "$dynamic_contacts_line" -lt "$device_fallback_line" ]
grep -Fq 'Page(${SLS_DIAL},b(sls-alert-autoanswer^s^1(${EXTEN}))A(${SLS_SAFE_SOUND})inq,' "${ROOT_DIR}/tools/install_release.sh"
grep -Fq 'for seconds in range(1,6)' "${ROOT_DIR}/tools/install_release.sh"

# Every call-file origin must remain alive for the measured combined WAV. A
# one-second origin destroys Page's ConfBridge before longer audio completes.
grep -Fq 'Data: {$pageHoldSeconds}' "$class_source"
grep -Fq 'return (int)ceil($duration) + 2;' "$class_source"
grep -Fq 'audio_page_hold_seconds()' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_test.sh"
grep -Fq 'Data: ${page_hold_seconds}' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_test.sh"
grep -Fq 'print rounded + 2' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_test.sh"
grep -Fq 'audio_page_hold_seconds()' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
grep -Fq 'Data: ${page_hold_seconds}' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
grep -Fq 'print rounded + 7' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
grep -Fq 'def audio_page_hold_seconds(sound):' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py"
grep -Fq 'Data: {page_hold_seconds}' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py"
grep -Fq 'return math.ceil(duration) + 2' "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py"
for producer in \
  "$class_source" \
  "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_test.sh" \
  "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh" \
  "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py"; do
  if grep -Fq 'Data: 1' "$producer"; then
    printf 'Paging call-file producer still contains a one-second origin lifetime: %s\n' "$producer" >&2
    exit 1
  fi
  if grep -Eq 'max\(33|rounded \+ 33|ceil\([^)]*\) \+ 33' "$producer"; then
    printf 'Paging call-file producer still includes the obsolete 30-second silent hold: %s\n' "$producer" >&2
    exit 1
  fi
done

expected_mapping=$'function|PJSIP_HEADER|res_pjsip_header_funcs.so\nfunction|PJSIP_CONTACT|func_pjsip_contact.so\nfunction|PJSIP_AOR|func_pjsip_aor.so\nfunction|PJSIP_DIAL_CONTACTS|chan_pjsip.so\nfunction|TOLOWER|func_strings.so\nfunction|CUT|func_strings.so\nfunction|FILTER|func_strings.so\nfunction|CHANNEL|func_channel.so\nfunction|IF|func_logic.so\nfunction|DB|func_db.so\nfunction|CALLERID|func_callerid.so\napplication|ConfBridge|app_confbridge.so\napplication|Page|app_page.so\napplication|ExecIf|app_exec.so\napplication|Gosub|app_stack.so\napplication|Return|app_stack.so\napplication|Log|app_verbose.so\napplication|Verbose|app_verbose.so\nrequired|application|Wait'
actual_mapping="$(
  ensure_asterisk_capability() {
    printf '%s|%s|%s\n' "$1" "$2" "$3"
  }
  require_asterisk_capability() {
    printf 'required|%s|%s\n' "$1" "$2"
  }
  ensure_required_asterisk_capabilities
)"
[ "$actual_mapping" = "$expected_mapping" ]

# The aggregate gate must stop and fail on the first missing capability.
if (
  ensure_asterisk_capability() {
    [ "$2" != "PJSIP_HEADER" ]
  }
  ensure_required_asterisk_capabilities
); then
  printf 'The aggregate Asterisk capability gate ignored a missing provider.\n' >&2
  exit 1
fi

printf 'Adaptive Asterisk capability regressions passed.\n'
