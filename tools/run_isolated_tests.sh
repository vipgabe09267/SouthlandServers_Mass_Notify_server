#!/usr/bin/env bash
# Run source fixtures without access to the PBX's network, state, or sockets.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ "${SLS_TEST_NAMESPACE:-}" != "entered" ]]; then
  if [[ "$EUID" -ne 0 ]]; then
    printf 'Run this isolation wrapper as root; it needs private mount/network/PID namespaces. No installer is run.\n' >&2
    exit 2
  fi
  exec unshare --mount --net --pid --fork --mount-proc env \
    SLS_TEST_NAMESPACE=entered \
    SLS_TEST_PARENT_MOUNT_NS="$(readlink /proc/self/ns/mnt)" \
    SLS_TEST_PARENT_NET_NS="$(readlink /proc/self/ns/net)" \
    SLS_TEST_PARENT_PID_NS="$(readlink /proc/self/ns/pid)" \
    bash "$0" "$@"
fi
# Never trust the stage marker alone: refuse all mounts if a caller inherited
# that variable without entering fresh namespaces.
for kind in MOUNT NET PID; do
  variable="SLS_TEST_PARENT_${kind}_NS"
  case "$kind" in MOUNT) path=mnt ;; NET) path=net ;; PID) path=pid ;; esac
  if [[ -z "${!variable:-}" || "${!variable}" == "$(readlink "/proc/self/ns/$path")" ]]; then
    printf 'Isolation verification failed for %s namespace; no fixture mounts were made.\n' "$kind" >&2
    exit 2
  fi
done
mount --make-rprivate /
mount --bind / /
mount -o remount,bind,ro /
mount -t tmpfs -o mode=1777,size=768M tmpfs /tmp
mount -t tmpfs -o mode=755,size=64M tmpfs /run
mount -t tmpfs -o mode=700,size=16M tmpfs /root
# A disposable Asterisk needs its vendor XML documentation to initialize Stasis.
# Preserve only this public module metadata before hiding all PBX state.
if [[ -f /var/lib/asterisk/documentation/core-en_US.xml && ! -L /var/lib/asterisk/documentation/core-en_US.xml ]]; then
  mkdir -p /tmp/sls-asterisk-vendor-docs
  cp /var/lib/asterisk/documentation/core-en_US.xml /tmp/sls-asterisk-vendor-docs/
fi
mount -t tmpfs -o mode=755,size=64M tmpfs /var/lib/asterisk
mount -t tmpfs -o mode=755,size=32M tmpfs /var/spool/asterisk
mount -t tmpfs -o mode=755,size=16M tmpfs /etc/asterisk
mount -t tmpfs -o mode=750,size=4M,gid="$(id -g asterisk)" tmpfs /etc/sls-mass-notify
mount -t tmpfs -o mode=755,size=32M tmpfs /var/log
mount --bind /dev/null /etc/freepbx.conf
mount -t tmpfs -o mode=755,size=32M tmpfs /usr/local/bin/sls_mass_notify
cp -a "$ROOT_DIR/slsmassnotifyserver/bin/sls_mass_notify/." /usr/local/bin/sls_mass_notify/
cp "$ROOT_DIR"/slsmassnotifyserver/bin/sls_mass_notify_*.{php,sh,py} /usr/local/bin/sls_mass_notify/ 2>/dev/null || true
chown -R root:root /usr/local/bin/sls_mass_notify
mkdir -p /var/lib/asterisk/SLS_Mass_Notifications_Plugin
export PYTHONDONTWRITEBYTECODE=1 TMPDIR=/tmp
umask 027
cd "$ROOT_DIR"
python3 -I "$ROOT_DIR/slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py" init >/dev/null
# Model transports start with an explicitly disabled policy in disposable storage.
# Missing/corrupt-policy tests use their own paths and keep production fail-closed checks.
python3 -I - <<'SLS_FIXTURE_POLICY'
import importlib.util
import pathlib
import pwd
import sys
sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('isolated_config_policy',pathlib.Path.cwd()/'slsmassnotifyserver/bin/sls_mass_notify/sls_config_crypto.py')
crypto=importlib.util.module_from_spec(spec);spec.loader.exec_module(crypto)
path=pathlib.Path('/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config')
if path.exists() or path.is_symlink():
    raise SystemExit('Disposable configuration policy unexpectedly exists.')
account=pwd.getpwnam('asterisk')
crypto._atomic_write(path,crypto.encode_config({'enterprise_cluster':{'enabled':False}}),account.pw_uid,account.pw_gid)
SLS_FIXTURE_POLICY
python3 - "$@" <<'PY'
import pathlib
import re
import subprocess
import sys

root = pathlib.Path.cwd()
if sys.argv[1:]:
    tests = [pathlib.Path(item) for item in sys.argv[1:]]
else:
    source = (root / 'tools/build_tgz.sh').read_text()
    tests = [pathlib.Path('tools') / name for name in re.findall(
        r'^(?:python3|php|bash|node) "\$\{ROOT_DIR\}/tools/(test_[^"/]+)"$', source, re.M)]
failures = []
for path in tests:
    if path.parent != pathlib.Path('tools') or not path.name.startswith('test_'):
        raise SystemExit('Only tools/test_* fixture paths are accepted: ' + str(path))
    interpreter = {'.py': 'python3', '.php': 'php', '.sh': 'bash', '.js': 'node'}.get(path.suffix)
    if not interpreter or not path.is_file():
        raise SystemExit('Unknown test fixture: ' + str(path))
    print('\nRUN ' + str(path), flush=True)
    try:
        result = subprocess.run([interpreter, str(path)], timeout=240, check=False)
        if result.returncode:
            failures.append(str(path))
    except subprocess.TimeoutExpired:
        failures.append(str(path) + ' (240-second limit)')
print(f'\n{len(tests) - len(failures)}/{len(tests)} fixture suites passed.', flush=True)
if failures:
    print('Failed: ' + ', '.join(failures), file=sys.stderr)
    raise SystemExit(1)
PY
