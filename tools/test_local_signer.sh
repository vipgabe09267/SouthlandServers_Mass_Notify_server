#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SIGNER="${ROOT_DIR}/slsmassnotifyserver/bin/sign_sls_mass_notify_local_sig.sh"
FIXTURE_ROOT="$(mktemp -d /tmp/sls-local-signer-test.XXXXXX)"
WEB_USER="www-data"
WEB_GROUP="$(id -gn "$WEB_USER")"
WEB_ROOT="${FIXTURE_ROOT}/web"
USER_HOME="${FIXTURE_ROOT}/webuser"
GPG_HOME="${USER_HOME}/.gnupg"
AST_SPOOL="${FIXTURE_ROOT}/spool"
SIGN_HOME="${FIXTURE_ROOT}/private-signing"
LOCK_FILE="${FIXTURE_ROOT}/signing.lock"
MODULE_NAME="signerfixture"
MODULE_DIR="${WEB_ROOT}/admin/modules/${MODULE_NAME}"

cleanup() {
  gpgconf --homedir "$GPG_HOME" --kill gpg-agent >/dev/null 2>&1 || true
  gpgconf --homedir "$SIGN_HOME" --kill gpg-agent >/dev/null 2>&1 || true
  if [ -d "$FIXTURE_ROOT" ]; then
    find "$FIXTURE_ROOT" -depth -delete
  fi
}
trap cleanup EXIT

chmod 0755 "$FIXTURE_ROOT"
install -d -m 0755 "$WEB_ROOT/admin/modules" "$AST_SPOOL"
install -d -m 0755 "$MODULE_DIR"
install -d -m 0700 -o "$WEB_USER" -g "$WEB_GROUP" "$USER_HOME" "$GPG_HOME"
printf '%s\n' 'local signer regression payload' >"${MODULE_DIR}/payload.txt"
chmod 0644 "${MODULE_DIR}/payload.txt"

# Seed a valid but wrong-owned FreePBX GPG home, matching restored/cloned PBXs
# where root operations have changed the keybox and trust database ownership.
runuser -u "$WEB_USER" -- env HOME="$USER_HOME" \
  gpg --homedir "$GPG_HOME" --batch --list-keys >/dev/null 2>&1
chown -R root:root "$GPG_HOME"
find "$GPG_HOME" -type d -exec chmod 0775 {} +
find "$GPG_HOME" -type f -exec chmod 0664 {} +

run_fixture_signer() {
  local force_verify_failure="${1:-0}"
  local module_name="${2:-$MODULE_NAME}"
  local force_backup_failure="${3:-0}"

  SLS_TEST_SIGNER="$SIGNER" \
  SLS_TEST_MODULE="$module_name" \
  SLS_TEST_SIGN_HOME="$SIGN_HOME" \
  SLS_TEST_LOCK_FILE="$LOCK_FILE" \
  SLS_TEST_WEB_USER="$WEB_USER" \
  SLS_TEST_WEB_GROUP="$WEB_GROUP" \
  SLS_TEST_WEB_ROOT="$WEB_ROOT" \
  SLS_TEST_USER_HOME="$USER_HOME" \
  SLS_TEST_GPG_HOME="$GPG_HOME" \
  SLS_TEST_AST_SPOOL="$AST_SPOOL" \
  SLS_TEST_FORCE_VERIFY_FAILURE="$force_verify_failure" \
  SLS_TEST_FORCE_BACKUP_FAILURE="$force_backup_failure" \
  bash -s <<'RUN_SIGNER'
set -euo pipefail
source "$SLS_TEST_SIGNER"
MODULE="$SLS_TEST_MODULE"
SIGN_HOME="$SLS_TEST_SIGN_HOME"
LOCK_FILE="$SLS_TEST_LOCK_FILE"

if [ "$SLS_TEST_FORCE_BACKUP_FAILURE" = "1" ]; then
  cp() {
    local argument
    for argument in "$@"; do
      [[ "$argument" == */module.sig.previous ]] && return 1
    done
    command cp "$@"
  }
fi

load_freepbx_metadata() {
  FREEPBX_WEB_USER="$SLS_TEST_WEB_USER"
  FREEPBX_WEB_GROUP="$SLS_TEST_WEB_GROUP"
  FREEPBX_WEB_ROOT="$SLS_TEST_WEB_ROOT"
  FREEPBX_USER_HOME="$SLS_TEST_USER_HOME"
  FREEPBX_GPG_HOME="$SLS_TEST_GPG_HOME"
  FREEPBX_ASTSPOOLDIR="$SLS_TEST_AST_SPOOL"
}

verify_published_signature() {
  local plaintext="$WORKDIR/verified-module.plain"
  local status_file="$WORKDIR/verified-module.status"
  local expected_hash

  if [ "$SLS_TEST_FORCE_VERIFY_FAILURE" = "1" ]; then
    return 1
  fi
  timeout 45 runuser -u "$FREEPBX_WEB_USER" -- \
    env HOME="$FREEPBX_USER_HOME" PATH="$PATH" \
    gpg --homedir "$FREEPBX_GPG_HOME" --batch --status-fd 2 \
    --output "$plaintext" --decrypt "$MODULE_SIG" 2>"$status_file"
  grep -Fq '[GNUPG:] VALIDSIG ' "$status_file"
  grep -Eq '\[GNUPG:\] TRUST_(ULTIMATE|FULLY)' "$status_file"
  expected_hash="$(sha256sum "$MODULE_DIR/payload.txt" | awk '{print $1}')"
  grep -Fqx "payload.txt = $expected_hash" "$plaintext"
  printf '%s\n' '{"status":129,"details":[]}'
}

main
RUN_SIGNER
}

# Two first-run signers must serialize around key creation and both finish with
# one usable private key and one valid final module signature.
run_fixture_signer >"${FIXTURE_ROOT}/signer-one.log" 2>&1 &
first_pid=$!
run_fixture_signer >"${FIXTURE_ROOT}/signer-two.log" 2>&1 &
second_pid=$!
wait "$first_pid"
wait "$second_pid"

secret_key_count="$(
  GNUPGHOME="$SIGN_HOME" gpg --homedir "$SIGN_HOME" --batch \
    --with-colons --list-secret-keys 2>/dev/null \
    | awk -F: '$1 == "sec" {count++} END {print count + 0}'
)"
[ "$secret_key_count" -eq 1 ]
[ -s "${MODULE_DIR}/module.sig" ]
[ "$(stat -c '%a %U:%G' "$GPG_HOME")" = "700 ${WEB_USER}:${WEB_GROUP}" ]
if find "$GPG_HOME" -xdev ! -user "$WEB_USER" -print -quit | grep -q .; then
  printf 'Signer did not repair all FreePBX GPG-home ownership.\n' >&2
  exit 1
fi

# A failed verification must leave the previous trusted signature byte-for-byte.
signature_hash_before="$(sha256sum "${MODULE_DIR}/module.sig" | awk '{print $1}')"
if run_fixture_signer 1 >"${FIXTURE_ROOT}/forced-failure.log" 2>&1; then
  printf 'Signer accepted an injected verification failure.\n' >&2
  exit 1
fi
[ "$(sha256sum "${MODULE_DIR}/module.sig" | awk '{print $1}')" = "$signature_hash_before" ]

# A backup failure occurs before publication and must not remove or replace the
# already trusted module signature.
if run_fixture_signer 0 "$MODULE_NAME" 1 >"${FIXTURE_ROOT}/forced-backup-failure.log" 2>&1; then
  printf 'Signer accepted an injected previous-signature backup failure.\n' >&2
  exit 1
fi
[ "$(sha256sum "${MODULE_DIR}/module.sig" | awk '{print $1}')" = "$signature_hash_before" ]

# Module names are identifiers, never paths.
if run_fixture_signer 0 '../unsafe-module' >"${FIXTURE_ROOT}/unsafe-module.log" 2>&1; then
  printf 'Signer accepted an unsafe module name.\n' >&2
  exit 1
fi

printf 'Transactional local-signing regressions passed.\n'
