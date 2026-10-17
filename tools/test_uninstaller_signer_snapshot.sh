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

# Keep the actual persistent-workspace checks inside this disposable tree.
python3 - "$ROOT_DIR/tools/uninstall_release.sh" "$fixture" <<'PYFIXTURE'
from pathlib import Path
import sys
source, fixture = map(Path, sys.argv[1:])
(fixture/'uninstall.sh').write_text(source.read_text().replace('/var/lib/sls-mass-notify-uninstaller',str(fixture/'root-recovery')))
PYFIXTURE
source "$fixture/uninstall.sh"

mock_signer="$fixture/sign_sls_mass_notify_local_sig.sh"
cat >"$mock_signer" <<'SH'
#!/usr/bin/env bash
acquire_signing_lock() { :; }
publish_candidate_transactionally() { :; }
approved_module_hashes() { :; }
SH
chmod 0755 "$mock_signer"
expected_hash="$(sha256sum "$mock_signer" | awk '{print $1}')"

export SLS_MASS_NOTIFY_SIGNER_SOURCE="$mock_signer"
export SLS_MASS_NOTIFY_TRUST_HELPER_SOURCE="$fixture/sls_module_trust.py"
printf '%s\n' '# Protected root helper fixture' >"$SLS_MASS_NOTIFY_TRUST_HELPER_SOURCE"
chmod 0755 "$SLS_MASS_NOTIFY_TRUST_HELPER_SOURCE"
snapshot_local_signer
[ -n "$RECOVERY_SIGNER" ] && [ -x "$RECOVERY_SIGNER" ]
[ "$(stat -c '%a %U:%G' "$RECOVERY_SIGNER")" = "700 root:root" ]
[ "$(sha256sum "$RECOVERY_SIGNER" | awk '{print $1}')" = "$expected_hash" ]

# The FreePBX uninstall hook removes both installed signer copies. The protected
# snapshot must remain usable through stock dashboard/framework restoration.
rm -f "$mock_signer" "$SLS_MASS_NOTIFY_TRUST_HELPER_SOURCE"
[ -f "$RECOVERY_SIGNER_DIR/sls_module_trust.py" ]
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
  verify_managed_sound_link_removed "$sound_fixture/foreign-link"
  ln -s "$DATA_DIR/sounds" "$sound_fixture/managed-remains"
  if verify_managed_sound_link_removed "$sound_fixture/managed-remains" >/dev/null 2>&1; then
    printf 'Uninstaller verification accepted a remaining managed sound link.\n' >&2
    exit 1
  fi
)

printf 'Uninstaller signer-snapshot regressions passed.\n'

# No old live-tree signing fallback is allowed when the protected signer was
# unavailable. This failure path cannot generate/import a key or bless files.
RECOVERY_SIGNER=""
if locally_sign_stock_module dashboard >/dev/null 2>&1; then
  printf 'Uninstaller accepted legacy signing without an approved inventory.\n' >&2
  exit 1
fi
