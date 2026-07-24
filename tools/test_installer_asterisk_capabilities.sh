#!/usr/bin/env bash
# shellcheck disable=SC2034
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "${ROOT_DIR}/tools/install_release.sh"

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
      printf '%s\n' "-- Info about channel driver: Local --"
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

expected_mapping=$'function|PJSIP_HEADER|res_pjsip_header_funcs.so\nfunction|PJSIP_CONTACT|func_pjsip_contact.so\nfunction|PJSIP_DIAL_CONTACTS|chan_pjsip.so\nfunction|TOLOWER|func_strings.so\nfunction|FILTER|func_strings.so\nfunction|CHANNEL|func_channel.so\nfunction|IF|func_logic.so\nfunction|DB|func_db.so\napplication|Dial|app_dial.so\napplication|ExecIf|app_exec.so\napplication|Return|app_stack.so\napplication|Log|app_verbose.so\napplication|Verbose|app_verbose.so'
actual_mapping="$(
  ensure_asterisk_capability() {
    printf '%s|%s|%s\n' "$1" "$2" "$3"
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
