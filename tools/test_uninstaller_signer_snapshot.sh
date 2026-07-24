#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "${ROOT_DIR}/tools/uninstall_release.sh"

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

printf 'Uninstaller signer-snapshot regressions passed.\n'
