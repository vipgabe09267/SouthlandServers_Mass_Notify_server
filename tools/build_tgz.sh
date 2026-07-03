#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE="slsmassnotifyserver"
VERSION="0.0.1-beta"
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
