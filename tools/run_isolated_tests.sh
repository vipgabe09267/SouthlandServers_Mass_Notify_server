#!/usr/bin/env bash
# Run source fixtures without access to the PBX's network, state, or sockets.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ "${SLS_TEST_NAMESPACE:-}" != "entered" ]]; then
  if [[ "$EUID" -ne 0 ]]; then
    printf 'Run this isolation wrapper as root; it needs private mount/network/PID namespaces. No installer is run.\n' >&2
    exit 2
  fi
  if [[ "${1:-}" != --single && "${1:-}" != --build ]]; then
    exec python3 -I "$ROOT_DIR/tools/isolated_dispatch.py" "$@"
  fi
  exec unshare --mount --net --pid --fork --kill-child=KILL --mount-proc env \
    SLS_TEST_NAMESPACE=entered \
    SLS_TEST_PARENT_MOUNT_NS="$(readlink /proc/self/ns/mnt)" \
    SLS_TEST_PARENT_NET_NS="$(readlink /proc/self/ns/net)" \
    SLS_TEST_PARENT_PID_NS="$(readlink /proc/self/ns/pid)" \
    bash "$0" "$@"
fi
if [[ "${1:-}" == --single ]]; then shift; fi
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
# Enable only the loopback interface in this already verified private network.
# Real HTTP/AMI fixtures need it; the namespace has no route to the PBX or WAN.
ip link set lo up
mount --make-rprivate /
BUILD_OUTPUT=""
if [[ "${1:-}" == --build ]]; then
  BUILD_OUTPUT="$ROOT_DIR/dist"
  mkdir -p "$BUILD_OUTPUT"
  # Keep only the artifact destination writable after the root bind is read-only.
  mount --bind "$BUILD_OUTPUT" "$BUILD_OUTPUT"
fi
mount --rbind / /
python3 -I - "$BUILD_OUTPUT" <<'SLS_READONLY_MOUNTS'
import re
import os
import subprocess
import sys

def unescape(value):
    return re.sub(r'\\([0-7]{3})', lambda match: chr(int(match[1], 8)), value)

with open('/proc/self/mountinfo', encoding='utf-8') as handle:
    rows = [line.split() for line in handle]
targets = {unescape(row[4]): {} for row in rows}
for row in rows:
    targets[unescape(row[4])][row[0]] = row[row.index('-') + 1]
for target in sorted(targets, key=lambda value: (value.count('/'), len(value)), reverse=True):
    if sys.argv[1] and target == sys.argv[1]:
        continue
    try:
        descriptor = os.open(target, os.O_PATH | os.O_CLOEXEC | os.O_NOFOLLOW)
    except (FileNotFoundError, NotADirectoryError):
        # An outer fixture may cover /run or /tmp with a private filesystem.
        # Its unreachable old child mounts cannot expose their hidden contents;
        # the visible ancestor is still remounted below.
        continue
    try:
        with open(f'/proc/self/fdinfo/{descriptor}', encoding='utf-8') as handle:
            actual = next(line.split()[1] for line in handle if line.startswith('mnt_id:'))
    finally:
        os.close(descriptor)
    if actual not in targets[target]:
        continue  # Covered mounts are protected by their visible ancestor.
    command = ['mount', '-t', 'tmpfs', '-o', 'ro,mode=000,size=4k', 'tmpfs', target] if targets[target][actual] == 'autofs' else ['mount', '-o', 'remount,bind,ro', target]
    subprocess.run(command, check=True, timeout=15)
SLS_READONLY_MOUNTS
# Keep one descriptor to public vendor metadata across nested /tmp and PBX
# state mounts. No live configuration, socket or database is carried through.
asterisk_documentation_fd=""
for documentation in /var/lib/asterisk/documentation/core-en_US.xml /tmp/sls-asterisk-vendor-docs/core-en_US.xml; do
  if [[ -f "$documentation" && ! -L "$documentation" ]]; then
    exec {asterisk_documentation_fd}<"$documentation"
    break
  fi
done
mount -t tmpfs -o mode=1777,size=768M tmpfs /tmp
mount -t tmpfs -o mode=755,size=64M tmpfs /run
mount -t tmpfs -o mode=700,size=16M tmpfs /root
if [[ -d /dev/shm && ! -L /dev/shm ]]; then
  mount -t tmpfs -o mode=1777,size=64M tmpfs /dev/shm
fi
# A disposable Asterisk needs its vendor XML documentation to initialize Stasis.
# Preserve only this public module metadata before hiding all PBX state.
if [[ -n "$asterisk_documentation_fd" ]]; then
  mkdir -p /tmp/sls-asterisk-vendor-docs
  python3 -I - "$asterisk_documentation_fd" <<'SLS_PUBLIC_DOCUMENTATION'
import os
import pathlib
import stat
import sys
descriptor = int(sys.argv[1])
before = os.fstat(descriptor)
if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or not 0 < before.st_size <= 4194304:
    raise SystemExit('Public Asterisk fixture metadata is unsafe or oversized.')
raw = bytearray()
while len(raw) <= 4194304:
    chunk = os.read(descriptor, min(65536, 4194305 - len(raw)))
    if not chunk:
        break
    raw.extend(chunk)
identity = lambda value: (value.st_dev, value.st_ino, value.st_mode, value.st_uid,
    value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)
if len(raw) != before.st_size or identity(os.fstat(descriptor)) != identity(before):
    raise SystemExit('Public Asterisk fixture metadata changed while copying.')
pathlib.Path('/tmp/sls-asterisk-vendor-docs/core-en_US.xml').write_bytes(raw)
SLS_PUBLIC_DOCUMENTATION
  exec {asterisk_documentation_fd}<&-
fi
mount -t tmpfs -o mode=755,size=64M tmpfs /var/lib/asterisk
mount -t tmpfs -o mode=755,size=32M tmpfs /var/spool/asterisk
if [[ -e /var/spool/postfix || -L /var/spool/postfix ]]; then
  [[ -d /var/spool/postfix && ! -L /var/spool/postfix ]] || { printf 'Postfix test isolation requires a real spool directory.\n' >&2; exit 2; }
  mount -t tmpfs -o mode=755,size=16M tmpfs /var/spool/postfix
fi
mount -t tmpfs -o mode=755,size=16M tmpfs /etc/asterisk
mount -t tmpfs -o mode=750,size=4M,gid="$(id -g asterisk)" tmpfs /etc/sls-mass-notify
mount -t tmpfs -o mode=755,size=32M tmpfs /var/log
if [[ -d /var/lib/sls-mass-notify-trust ]]; then
  mount -t tmpfs -o mode=700,size=64M tmpfs /var/lib/sls-mass-notify-trust
fi
if [[ -d /var/lib/sls-mass-notify-installer ]]; then
  mount -t tmpfs -o mode=700,size=64M tmpfs /var/lib/sls-mass-notify-installer
fi
mount --bind /dev/null /etc/freepbx.conf
mount -t tmpfs -o mode=755,size=32M tmpfs /usr/local/bin/sls_mass_notify
cp -a "$ROOT_DIR/slsmassnotifyserver/bin/sls_mass_notify/." /usr/local/bin/sls_mass_notify/
(
  shopt -s nullglob
  helpers=("$ROOT_DIR"/slsmassnotifyserver/bin/sls_mass_notify_*.{php,sh,py})
  ((${#helpers[@]})) || { printf 'Isolated runtime helper sources are missing.\n' >&2; exit 2; }
  # Match protected installation modes independently of the caller's umask.
  install -m 0755 -- "${helpers[@]}" /usr/local/bin/sls_mass_notify/
)
chown -R root:root /usr/local/bin/sls_mass_notify
mkdir -p /var/lib/asterisk/SLS_Mass_Notifications_Plugin
export PYTHONDONTWRITEBYTECODE=1 TMPDIR=/tmp SLS_TEST_READONLY_SOURCE="$ROOT_DIR"
mkdir -m 0700 /tmp/sls-browser-config /tmp/sls-browser-cache
export XDG_CONFIG_HOME=/tmp/sls-browser-config XDG_CACHE_HOME=/tmp/sls-browser-cache
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
if [[ "${1:-}" == --build ]]; then
  # Nested suites replace /tmp and /run with fresh filesystems. Keep their
  # read-only source outside those paths, in the ignored artifact directory.
  workspace="$(mktemp -d "$BUILD_OUTPUT/.sls-isolated-build.XXXXXXXX")"
  trap 'rm -rf -- "$workspace"' EXIT
  chmod 0755 "$workspace"
  tar -C "$ROOT_DIR" --exclude='./.git' --exclude='./dist' --exclude='__pycache__' --exclude='*.pyc' -cf - . \
    | tar -C "$workspace" -xf -
  chown -R root:root "$workspace"
  bash "$workspace/tools/build_tgz.sh" --unsigned
  cp "$workspace"/dist/slsmassnotifyserver-*.tgz "$BUILD_OUTPUT/"
  printf 'Isolated build passed; unsigned candidate copied to %s. Sign and verify the release manifest before publication.\n' "$BUILD_OUTPUT"
  exit 0
fi
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
