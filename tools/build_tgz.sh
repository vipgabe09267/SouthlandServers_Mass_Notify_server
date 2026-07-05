#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE="slsmassnotifyserver"
VERSION="$(php -r '$x=simplexml_load_file($argv[1]); if (!$x) exit(1); echo (string)$x->version;' "${ROOT_DIR}/${MODULE}/module.xml")"
DIST_DIR="${ROOT_DIR}/dist"
PACKAGE="${DIST_DIR}/${MODULE}-${VERSION}.tgz"

mkdir -p "${DIST_DIR}"
rm -f "${PACKAGE}"

tar \
  --exclude='module.sig' \
  --exclude='__pycache__' \
  --exclude='*.pyc' \
  --exclude='seen_alerts.json' \
  -C "${ROOT_DIR}" \
  -czf "${PACKAGE}" \
  "${MODULE}"

echo "${PACKAGE}"
