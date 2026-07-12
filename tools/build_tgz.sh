#!/usr/bin/env bash
set -euo pipefail

umask 027

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE="slsmassnotifyserver"
VERSION="$(php -r '$x=simplexml_load_file($argv[1]); if (!$x) exit(1); echo (string)$x->version;' "${ROOT_DIR}/${MODULE}/module.xml")"
DIST_DIR="${ROOT_DIR}/dist"
PACKAGE="${DIST_DIR}/${MODULE}-${VERSION}.tgz"

mkdir -p "${DIST_DIR}"
rm -f "${PACKAGE}"

for document in README.md INSTALL.md CHANGELOG.md SECURITY.md PHONE_FORMATS.md LICENSE; do
  cmp -s "${ROOT_DIR}/${document}" "${ROOT_DIR}/${MODULE}/${document}" || {
    printf 'Root and module copies differ: %s\n' "$document" >&2
    exit 1
  }
done

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "${ROOT_DIR}/${MODULE}" -type f -name '*.php' -print0)

while IFS= read -r -d '' file; do
  bash -n "$file"
done < <(find "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools" -type f -name '*.sh' -print0)

python3 - "${ROOT_DIR}/${MODULE}" <<'PY'
import ast
import pathlib
import sys

for path in pathlib.Path(sys.argv[1]).rglob("*.py"):
    ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
PY

php -r '$xml = simplexml_load_file($argv[1]); if (!$xml || trim((string)$xml->rawname) !== $argv[2] || trim((string)$xml->version) !== $argv[3]) exit(1);' \
  "${ROOT_DIR}/${MODULE}/module.xml" "$MODULE" "$VERSION"

if find "${ROOT_DIR}/${MODULE}" -type f \( \
  -name 'module.sig' -o -name '*.pyc' -o -name '*.pyo' -o -name '*.bak*' \
  -o -name '*.orig' -o -name '*.rej' -o -name '*~' -o -name '.DS_Store' \
  -o -name '*.config' -o -name '*.pending.json' -o -name '*.onnx' -o -name '*.onnx.json' \
\) -print -quit | grep -q .; then
  printf 'Module tree contains a generated, private, cache, or backup artifact.\n' >&2
  exit 1
fi
if find "${ROOT_DIR}/${MODULE}" -type d -name '__pycache__' -print -quit | grep -q .; then
  printf 'Module tree contains a Python cache directory.\n' >&2
  exit 1
fi
if grep -RIlE 'ghp_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+' "${ROOT_DIR}/${MODULE}" "${ROOT_DIR}/tools" | grep -q .; then
  printf 'A GitHub credential pattern was found in release source.\n' >&2
  exit 1
fi

for required in \
  module.xml Slsmassnotifyserver.class.php install.php uninstall.php \
  bin/sls_mass_notify/sls_notify.py bin/sls_mass_notify/sls_config.py \
  bin/sls_mass_notify_nws_poll.sh bin/sls_mass_notify_test.sh \
  bin/sls_mass_notify_update.sh bin/sls_mass_notify_maintenance.sh \
  bin/sls_mass_notify_uninstall.sh \
  bin/sls_mass_notify_install_piper_voices.sh; do
  [ -f "${ROOT_DIR}/${MODULE}/${required}" ] || {
    printf 'Required module file is missing: %s\n' "$required" >&2
    exit 1
  }
done

tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner \
  --exclude='module.sig' --exclude='__pycache__' --exclude='*.pyc' \
  -C "${ROOT_DIR}" -cf - "${MODULE}" | gzip -n -9 > "${PACKAGE}"
chmod 0640 "${PACKAGE}"

TGZ_PATH="${PACKAGE}" MODULE_NAME="${MODULE}" MODULE_VERSION="${VERSION}" python3 - <<'PY'
import os
import pathlib
import tarfile
import xml.etree.ElementTree as ET

archive = os.environ["TGZ_PATH"]
module = os.environ["MODULE_NAME"]
version = os.environ["MODULE_VERSION"]
total = 0
with tarfile.open(archive, "r:gz") as handle:
    members = handle.getmembers()
    if not members or len(members) > 2000:
        raise SystemExit("invalid archive member count")
    names = {member.name for member in members}
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or ".." in path.parts or not path.parts or path.parts[0] != module:
            raise SystemExit(f"unsafe archive path: {member.name}")
        if member.issym() or member.islnk() or member.isdev() or member.isfifo() or member.mode & 0o6000:
            raise SystemExit(f"unsafe archive member: {member.name}")
        if member.isfile():
            total += member.size
    if total > 50 * 1024 * 1024:
        raise SystemExit("archive expands beyond 50 MB")
    module_xml = handle.extractfile(f"{module}/module.xml")
    if module_xml is None:
        raise SystemExit("module.xml missing")
    root = ET.fromstring(module_xml.read())
    if (root.findtext("rawname") or "").strip() != module:
        raise SystemExit("module rawname mismatch")
    if (root.findtext("version") or "").strip() != version:
        raise SystemExit("module version mismatch")
PY

sha256sum "${PACKAGE}"
printf '%s\n' "${PACKAGE}"
