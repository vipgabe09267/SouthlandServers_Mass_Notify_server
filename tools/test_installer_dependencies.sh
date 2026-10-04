#!/bin/bash
set -euo pipefail
[[ "${SLS_TEST_NAMESPACE:-}" == entered ]] || { printf 'Use the verified private test wrapper.\n' >&2; exit 2; }
[[ "$(readlink /proc/self/ns/mnt)" != "${SLS_TEST_PARENT_MOUNT_NS:-}" ]] || exit 2
task_dir="$(mktemp -d /tmp/sls-dependency-fixture.XXXXXX)"
php_target="$(readlink -f /usr/bin/php)"
trap 'umount "$php_target"; rm -rf "$task_dir"' EXIT
cat > "$task_dir/php" <<'PHP'
#!/bin/bash
set -eu
printf '%s\n' "$*" >> "$SLS_DEPENDENCY_TRACE"
if [[ "$*" == *PHP_MAJOR_VERSION* ]]; then printf '8.2'; exit 0; fi
if [[ "$*" == *pdo_sqlite* || "$*" == *curl_init* || "$*" == *DOMDocument* || "$*" == *mbstring* || "$*" == *openssl_encrypt* ]]; then
  [[ -f "$SLS_DEPENDENCY_INSTALLED" ]] || exit 1
fi
exit 0
PHP
cat > "$task_dir/apt-get" <<'APT'
#!/bin/bash
set -eu
printf '%s\n' "$*" >> "$SLS_DEPENDENCY_APT_TRACE"
[[ "$*" == install\ * ]] || exit 8
[[ "$SLS_DEPENDENCY_SCENARIO" != package_failure ]] || exit 9
if [[ "$SLS_DEPENDENCY_SCENARIO" != verification_failure ]]; then touch "$SLS_DEPENDENCY_INSTALLED"; fi
APT
chmod 0755 "$task_dir/php" "$task_dir/apt-get"
mount --bind "$task_dir/php" "$php_target"
export PATH="$task_dir:$PATH"
export SLS_DEPENDENCY_TRACE="$task_dir/php-trace" SLS_DEPENDENCY_INSTALLED="$task_dir/installed" SLS_DEPENDENCY_APT_TRACE="$task_dir/apt-trace"
installer="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/install_release.sh"
for scenario in success present verification_failure package_failure metadata_failure; do
  rm -f "$SLS_DEPENDENCY_TRACE" "$SLS_DEPENDENCY_INSTALLED" "$SLS_DEPENDENCY_APT_TRACE"
  [[ "$scenario" != present ]] || touch "$SLS_DEPENDENCY_INSTALLED"
  export SLS_DEPENDENCY_SCENARIO="$scenario"
  set +e
  bash -c 'source "$1"; log(){ printf "%s\n" "$*"; }; refresh_apt_metadata(){ [[ "$SLS_DEPENDENCY_SCENARIO" != metadata_failure ]]; }; install_dependencies' fixture "$installer" > "$task_dir/result" 2>&1
  status=$?
  set -e
  case "$scenario" in
    success)
      [[ "$status" == 0 && -f "$SLS_DEPENDENCY_INSTALLED" ]]
      for package in php8.2-sqlite3 php8.2-curl php8.2-xml php8.2-mbstring php8.2-common; do grep -Fq "$package" "$SLS_DEPENDENCY_APT_TRACE"; done
      ;;
    present) [[ "$status" == 0 && ! -f "$SLS_DEPENDENCY_APT_TRACE" ]] ;;
    verification_failure) [[ "$status" != 0 ]]; grep -Fq 'still lacks SQLite, cURL or XML after dependency installation' "$task_dir/result" ;;
    package_failure) [[ "$status" != 0 && ! -f "$SLS_DEPENDENCY_INSTALLED" ]] ;;
    metadata_failure) [[ "$status" != 0 && ! -f "$SLS_DEPENDENCY_APT_TRACE" ]]; grep -Fq 'Unable to refresh Debian package metadata' "$task_dir/result" ;;
  esac
done
printf 'Installer dependency recovery: install before verification, version-matched PHP extensions, existing packages and actionable package/metadata/runtime failures passed.\n'
