#!/usr/bin/env bash
# Read-only checks shared by CI and release preparation. Never bootstrap FreePBX.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
for document in README.md INSTALL.md CHANGELOG.md SECURITY.md PHONE_FORMATS.md LICENSE; do
  cmp -s "$ROOT_DIR/$document" "$ROOT_DIR/slsmassnotifyserver/$document" || {
    printf 'Documentation copies differ: %s\n' "$document" >&2
    exit 1
  }
done
python3 -B "$ROOT_DIR/tools/verify_passkey_vendor.py"
PHP_LINT_VERSION_ID="$(php -n -r 'echo PHP_VERSION_ID;')"
while IFS= read -r -d '' file; do
  # The pinned upstream loader requires this file only on PHP 8.5 and later.
  if [[ "$file" == "$ROOT_DIR/slsmassnotifyserver/passkey-libs/vendor/symfony/polyfill-uuid/bootstrap85.php" && "$PHP_LINT_VERSION_ID" -lt 80500 ]]; then continue; fi
  php -l "$file" >/dev/null
done < <(find "$ROOT_DIR/slsmassnotifyserver" -type f -name '*.php' -print0)
while IFS= read -r -d '' file; do bash -n "$file"; done < <(find "$ROOT_DIR/slsmassnotifyserver" "$ROOT_DIR/tools" -type f -name '*.sh' -print0)
python3 - "$ROOT_DIR" <<'PY'
import ast, pathlib, sys
root = pathlib.Path(sys.argv[1])
for directory in ['slsmassnotifyserver', 'tools']:
    for path in (root / directory).rglob('*.py'):
        ast.parse(path.read_text(encoding='utf-8'), filename=str(path))
print('PHP, shell, Python and mirrored documentation checks passed.')
PY
