#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if SLS_MASS_NOTIFY_MODULE='../dashboard' \
  bash -c 'source "$1"' _ "${ROOT_DIR}/tools/uninstall_release.sh" \
  >/dev/null 2>&1; then
  printf 'Uninstaller accepted an unsafe alternate module rawname.\n' >&2
  exit 1
fi
if SLS_MASS_NOTIFY_MODULE='othermodule' \
  bash -c 'source "$1"' _ "${ROOT_DIR}/tools/uninstall_release.sh" \
  >/dev/null 2>&1; then
  printf 'Uninstaller accepted a different FreePBX module rawname.\n' >&2
  exit 1
fi
source "${ROOT_DIR}/tools/uninstall_release.sh"

run_fwconsole_body="$(declare -f run_fwconsole)"
close_line="$(grep -nFm1 'close_inherited_maintenance_lock_fds' <<<"$run_fwconsole_body" | cut -d: -f1)"
php_line="$(grep -nFm1 'php -d pcre.jit=0' <<<"$run_fwconsole_body" | cut -d: -f1)"
[ -n "$close_line" ] && [ -n "$php_line" ] && [ "$close_line" -lt "$php_line" ] || {
  printf 'Uninstaller fwconsole children can inherit the maintenance lock descriptor.\n' >&2
  exit 1
}

cmp -s \
  "${ROOT_DIR}/tools/uninstall_release.sh" \
  "${ROOT_DIR}/slsmassnotifyserver/bin/sls_mass_notify_uninstall.sh" || {
  printf 'Packaged and public uninstaller copies differ.\n' >&2
  exit 1
}

fixture="$(mktemp -d /tmp/sls-uninstaller-signer-test.XXXXXX)"
trap 'cleanup_recovery_signer; find "$fixture" -depth -delete' EXIT

mock_signer="$fixture/sign_sls_mass_notify_local_sig.sh"
cat >"$mock_signer" <<'SH'
#!/usr/bin/env bash
acquire_signing_lock() { :; }
publish_candidate_transactionally() { :; }
SH
chmod 0755 "$mock_signer"
expected_hash="$(sha256sum "$mock_signer" | awk '{print $1}')"

export SLS_MASS_NOTIFY_SIGNER_SOURCE="$mock_signer"
snapshot_local_signer
[ -n "$RECOVERY_SIGNER" ] && [ -x "$RECOVERY_SIGNER" ]
[ "$(stat -c '%a %U:%G' "$RECOVERY_SIGNER")" = "700 root:root" ]
[ "$(sha256sum "$RECOVERY_SIGNER" | awk '{print $1}')" = "$expected_hash" ]

# The FreePBX uninstall hook removes both installed signer copies. The protected
# snapshot must remain usable through stock dashboard/framework restoration.
rm -f "$mock_signer"
bash -n "$RECOVERY_SIGNER"
cleanup_recovery_signer
[ -z "$RECOVERY_SIGNER" ] && [ -z "$RECOVERY_SIGNER_DIR" ]

(
  lock_dir="$(mktemp -d /tmp/sls-uninstaller-lock-test.XXXXXX)"
  trap 'find "$lock_dir" -depth -delete' EXIT
  SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$lock_dir/maintenance.lock"
  UNINSTALL_MAINTENANCE_LOCK_FD=""
  acquire_maintenance_coordination
  [ -n "$UNINSTALL_MAINTENANCE_LOCK_FD" ]
  if flock -n "$SLS_MASS_NOTIFY_MAINTENANCE_LOCK" -c true; then
    printf 'Uninstaller maintenance lock did not exclude a competing process.\n' >&2
    exit 1
  fi
)

(
  lock_dir="$(mktemp -d /tmp/sls-uninstaller-inherited-lock-test.XXXXXX)"
  trap 'find "$lock_dir" -depth -delete' EXIT
  inherited_fd=""
  maintenance_lock="$lock_dir/maintenance.lock"
  exec {inherited_fd}>"$maintenance_lock"
  flock -n "$inherited_fd"
  SLS_TEST_UNINSTALLER="${ROOT_DIR}/tools/uninstall_release.sh" \
  SLS_MASS_NOTIFY_MAINTENANCE_LOCK="$maintenance_lock" \
    bash -c '
      set -euo pipefail
      source "$SLS_TEST_UNINSTALLER"
      log() { :; }
      UNINSTALL_MAINTENANCE_LOCK_FD=""
      acquire_maintenance_coordination
      [ -z "$UNINSTALL_MAINTENANCE_LOCK_FD" ]
    '
)

(
  preserve_fixture="$(mktemp -d /tmp/sls-uninstaller-preserve-test.XXXXXX)"
  trap 'find "$preserve_fixture" -depth -delete' EXIT
  DATA_DIR="$preserve_fixture/data"
  mkdir -p "$DATA_DIR/config-backups" "$DATA_DIR/sounds/tones"
  printf '%s\n' '{"setup_complete":"1"}' >"$DATA_DIR/mass-notifications.config"
  printf '%s\n' '{"backup":true}' >"$DATA_DIR/config-backups/backup.json"
  printf '%s\n' 'tone-data' >"$DATA_DIR/sounds/tones/custom.wav"
  CONFIG_TMP=""
  PURGE_CONFIG=0
  preserve_user_data
  [ ! -L "$CONFIG_TMP/mass-notifications.config" ]
  [ "$(cat "$CONFIG_TMP/mass-notifications.config")" = '{"setup_complete":"1"}' ]
  [ "$(cat "$CONFIG_TMP/config-backups/backup.json")" = '{"backup":true}' ]
  [ "$(cat "$CONFIG_TMP/sounds/tones/custom.wav")" = 'tone-data' ]
  if find "$CONFIG_TMP" -type l -print -quit | grep -q .; then
    printf 'Safe preservation copied a symbolic link.\n' >&2
    exit 1
  fi
  find "$CONFIG_TMP" -depth -delete
  CONFIG_TMP=""
)

(
  preserve_fixture="$(mktemp -d /tmp/sls-uninstaller-symlink-test.XXXXXX)"
  trap 'find "$preserve_fixture" -depth -delete' EXIT
  DATA_DIR="$preserve_fixture/data"
  mkdir -p "$DATA_DIR"
  printf '%s\n' 'ROOT-ONLY-SENTINEL' >"$preserve_fixture/protected"
  chmod 0600 "$preserve_fixture/protected"
  ln -s "$preserve_fixture/protected" "$DATA_DIR/mass-notifications.config"
  CONFIG_TMP=""
  PURGE_CONFIG=0
  if preserve_user_data >/dev/null 2>&1; then
    printf 'Uninstaller preserved a symbolic-link central configuration.\n' >&2
    exit 1
  fi
  if [ -n "$CONFIG_TMP" ] && grep -Rqs 'ROOT-ONLY-SENTINEL' "$CONFIG_TMP"; then
    printf 'Uninstaller disclosed a symbolic-link target into its snapshot.\n' >&2
    exit 1
  fi
  [ -z "$CONFIG_TMP" ] || find "$CONFIG_TMP" -depth -delete
  CONFIG_TMP=""
)

(
  preserve_fixture="$(mktemp -d /tmp/sls-uninstaller-tree-symlink-test.XXXXXX)"
  trap 'find "$preserve_fixture" -depth -delete' EXIT
  DATA_DIR="$preserve_fixture/data"
  mkdir -p "$DATA_DIR/config-backups" "$DATA_DIR/sounds/tones"
  printf '%s\n' 'ROOT-ONLY-BACKUP' >"$preserve_fixture/protected"
  ln -s "$preserve_fixture/protected" "$DATA_DIR/config-backups/backup.json"
  CONFIG_TMP=""
  PURGE_CONFIG=0
  if preserve_user_data >/dev/null 2>&1; then
    printf 'Uninstaller accepted a symbolic link in preserved config backups.\n' >&2
    exit 1
  fi
  [ -z "$CONFIG_TMP" ] || find "$CONFIG_TMP" -depth -delete
  CONFIG_TMP=""
  rm -f "$DATA_DIR/config-backups/backup.json"
  ln -s "$preserve_fixture/protected" "$DATA_DIR/sounds/tones/custom.wav"
  if preserve_user_data >/dev/null 2>&1; then
    printf 'Uninstaller accepted a symbolic link in preserved uploaded tones.\n' >&2
    exit 1
  fi
  [ -z "$CONFIG_TMP" ] || find "$CONFIG_TMP" -depth -delete
  CONFIG_TMP=""
)

(
  preserve_fixture="$(mktemp -d /tmp/sls-uninstaller-directory-symlink-test.XXXXXX)"
  trap 'find "$preserve_fixture" -depth -delete' EXIT
  mkdir -p "$preserve_fixture/real-data"
  ln -s "$preserve_fixture/real-data" "$preserve_fixture/data"
  DATA_DIR="$preserve_fixture/data"
  CONFIG_TMP=""
  PURGE_CONFIG=0
  if preserve_user_data >/dev/null 2>&1; then
    printf 'Uninstaller accepted a symbolic-link persistent-data directory.\n' >&2
    exit 1
  fi
  [ -z "$CONFIG_TMP" ] || find "$CONFIG_TMP" -depth -delete
  CONFIG_TMP=""
)

(
  sound_fixture="$(mktemp -d /tmp/sls-uninstaller-sound-link-test.XXXXXX)"
  trap 'find "$sound_fixture" -depth -delete' EXIT
  DATA_DIR="$sound_fixture/data"
  mkdir -p "$DATA_DIR/sounds" "$sound_fixture/real-user-directory"
  printf '%s\n' 'keep-me' >"$sound_fixture/real-user-directory/user.wav"
  remove_managed_sound_link "$sound_fixture/real-user-directory"
  [ "$(cat "$sound_fixture/real-user-directory/user.wav")" = 'keep-me' ]
  ln -s "$DATA_DIR/sounds" "$sound_fixture/managed-link"
  remove_managed_sound_link "$sound_fixture/managed-link"
  [ ! -e "$sound_fixture/managed-link" ] && [ ! -L "$sound_fixture/managed-link" ]
  ln -s "$sound_fixture/real-user-directory" "$sound_fixture/foreign-link"
  remove_managed_sound_link "$sound_fixture/foreign-link"
  [ -L "$sound_fixture/foreign-link" ]
)

if [ "$(id -u)" -eq 0 ]; then
  (
    restore_fixture="$(mktemp -d /tmp/sls-uninstaller-restore-safety.XXXXXX)"
    trap 'find "$restore_fixture" -depth -delete' EXIT
    CONFIG_TMP="$restore_fixture/snapshot"
    DATA_DIR="$restore_fixture/target/mass-notify-data"
    mkdir -p "$CONFIG_TMP/config-backups" "$CONFIG_TMP/sounds/tones" "$restore_fixture/target" "$restore_fixture/protected"
    printf '%s\n' '{"setup_complete":"1"}' >"$CONFIG_TMP/mass-notifications.config"
    printf '%s\n' 'backup' >"$CONFIG_TMP/config-backups/one.config"
    printf '%s\n' 'tone' >"$CONFIG_TMP/sounds/tones/one.wav"
    printf '%s\n' 'do-not-touch' >"$restore_fixture/protected/sentinel"
    ln -s "$restore_fixture/protected" "$DATA_DIR"
    if restore_user_data >/dev/null 2>&1; then
      printf 'Uninstaller restored through a symbolic-link destination.\n' >&2
      exit 1
    fi
    [ "$(cat "$restore_fixture/protected/sentinel")" = 'do-not-touch' ]
    rm -f "$DATA_DIR"
    restore_user_data
    [ -z "$CONFIG_TMP" ]
    [ "$(cat "$DATA_DIR/mass-notifications.config")" = '{"setup_complete":"1"}' ]
    [ "$(cat "$DATA_DIR/config-backups/one.config")" = 'backup' ]
    [ "$(cat "$DATA_DIR/sounds/tones/one.wav")" = 'tone' ]
    [ "$(stat -c '%a %U:%G' "$DATA_DIR")" = '750 asterisk:asterisk' ]
    [ "$(stat -c '%a %U:%G' "$DATA_DIR/mass-notifications.config")" = '640 asterisk:asterisk' ]
    [ "$(stat -c '%a %U:%G' "$DATA_DIR/sounds/tones/one.wav")" = '644 asterisk:asterisk' ]
  )
fi

printf 'Uninstaller signer-snapshot regressions passed.\n'
