#!/bin/bash
set -euo pipefail

umask 027

VOICE_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices"
PIPER_DIR="/usr/local/bin/sls_mass_notify/piper"
PIPER_BIN="$PIPER_DIR/venv/bin/piper"
PIPER_PY="$PIPER_DIR/venv/bin/python"
LOG_FILE="/var/log/sls_mass_notify.log"
PIPER_ARTIFACTS_CHANGED=0
PIPER_DEPENDENCY_CHECK="/usr/local/bin/sls_mass_notify/sls_piper_dependencies.py"
PIPER_REQUIREMENTS="/usr/local/bin/sls_mass_notify/piper-requirements.txt"

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

download_file() {
  local url="$1"
  local target="$2"
  local expected_sha="$3"
  local tmp

  tmp="$(mktemp "${TMPDIR:-/tmp}/sls-piper-voice.XXXXXXXX")" || return 1
  if command -v curl >/dev/null 2>&1; then
    if ! curl -fL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 900 \
      -A "SouthlandServers-Mass-Notifications-Server/0.1.5-beta" \
      -o "$tmp" "$url"; then
      rm -f "$tmp"
      return 1
    fi
  elif command -v wget >/dev/null 2>&1; then
    if ! wget --tries=5 --timeout=900 -O "$tmp" "$url"; then
      rm -f "$tmp"
      return 1
    fi
  else
    log "Piper voice download failed: curl/wget missing"
    rm -f "$tmp"
    return 1
  fi

  if ! printf '%s  %s\n' "$expected_sha" "$tmp" | sha256sum -c - >/dev/null 2>&1; then
    log "Piper voice checksum verification failed for $(basename "$target")"
    rm -f "$tmp"
    return 1
  fi

  if [[ "$target" == *.onnx ]]; then
    [ -s "$tmp" ] && [ "$(stat -c%s "$tmp" 2>/dev/null || echo 0)" -gt 1000000 ] || {
      rm -f "$tmp"
      return 1
    }
  elif [[ "$target" == *.json ]]; then
    python3 -I -m json.tool "$tmp" >/dev/null 2>&1 || {
      rm -f "$tmp"
      return 1
    }
  fi

  SLS_PIPER_VOICE_SOURCE="$tmp" SLS_PIPER_VOICE_TARGET="$target" /usr/bin/python3 -I - <<'PY' || {
import os
import pwd
import secrets
import stat

source = os.environ["SLS_PIPER_VOICE_SOURCE"]
target = os.environ["SLS_PIPER_VOICE_TARGET"]
name = os.path.basename(target)
if target != "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/" + name \
        or not __import__("re").fullmatch(r"[a-z]{2}_[A-Z]{2}-[A-Za-z0-9_-]+\.onnx(?:\.json)?", name) \
        or not (name.endswith(".onnx") or name.endswith(".onnx.json")):
    raise SystemExit(2)
directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
root_fd = os.open("/", directory_flags)
voices_fd = -1
temporary = ""
try:
    current = root_fd
    for component in ("var", "lib", "asterisk", "SLS_Mass_Notifications_Plugin", "piper", "voices"):
        next_fd = os.open(component, directory_flags, dir_fd=current)
        if current != root_fd:
            os.close(current)
        current = next_fd
    voices_fd = current
    temporary = ".voice." + secrets.token_hex(8)
    output_fd = os.open(
        temporary,
        os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0),
        0o600,
        dir_fd=voices_fd,
    )
    try:
        with open(source, "rb") as input_handle, os.fdopen(output_fd, "wb") as output_handle:
            output_fd = -1
            while True:
                chunk = input_handle.read(1024 * 1024)
                if not chunk:
                    break
                output_handle.write(chunk)
            output_handle.flush()
            os.fsync(output_handle.fileno())
            account = pwd.getpwnam("asterisk")
            os.fchown(output_handle.fileno(), account.pw_uid, account.pw_gid)
            os.fchmod(output_handle.fileno(), 0o644)
        existing = None
        try:
            existing = os.stat(name, dir_fd=voices_fd, follow_symlinks=False)
        except FileNotFoundError:
            pass
        if existing is not None and not stat.S_ISREG(existing.st_mode):
            os.unlink(temporary, dir_fd=voices_fd)
            raise RuntimeError("Piper voice target is not a regular file")
        os.replace(temporary, name, src_dir_fd=voices_fd, dst_dir_fd=voices_fd)
        temporary = ""
        os.fsync(voices_fd)
    finally:
        if output_fd >= 0:
            os.close(output_fd)
        if temporary and voices_fd >= 0:
            try:
                os.unlink(temporary, dir_fd=voices_fd)
            except FileNotFoundError:
                pass
        if voices_fd >= 0:
            os.close(voices_fd)
finally:
    os.close(root_fd)
PY
    rm -f "$tmp"
    return 1
  }
  rm -f "$tmp"
  return 0
}

reset_managed_piper_venv() {
  PIPER_RESET_ROOT="$PIPER_DIR" /usr/bin/python3 -I - <<'PY'
import os
import shutil
import stat
import time

root = os.environ["PIPER_RESET_ROOT"]
if root != "/usr/local/bin/sls_mass_notify/piper":
    raise SystemExit("Piper repair refused: runtime path is not the SLS-managed path")
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | os.O_NOFOLLOW
deadline = time.monotonic() + 10
entries = 0

def trusted(metadata, label):
    if metadata.st_uid != 0 or (not stat.S_ISLNK(metadata.st_mode) and metadata.st_mode & 0o022):
        raise RuntimeError("Piper repair refused: " + label + " is not root-owned and protected from writes")

def inspect_tree(descriptor, device):
    global entries
    trusted(os.fstat(descriptor), "runtime directory")
    for name in os.listdir(descriptor):
        entries += 1
        if entries > 50000 or time.monotonic() > deadline:
            raise RuntimeError("Piper repair refused: runtime inspection exceeded its bounded safety limit")
        metadata = os.stat(name, dir_fd=descriptor, follow_symlinks=False)
        trusted(metadata, "runtime entry")
        if metadata.st_dev != device:
            raise RuntimeError("Piper repair refused: runtime contains another mounted filesystem")
        if stat.S_ISDIR(metadata.st_mode):
            child = os.open(name, flags, dir_fd=descriptor)
            try:
                inspect_tree(child, device)
            finally:
                os.close(child)
        elif stat.S_ISREG(metadata.st_mode):
            if metadata.st_nlink != 1:
                raise RuntimeError("Piper repair refused: runtime contains a hard-linked file")
        elif not stat.S_ISLNK(metadata.st_mode):
            raise RuntimeError("Piper repair refused: runtime contains an unsupported file type")
        # Standard venv interpreter/lib64 symlinks are unlinked, never traversed.

descriptor = os.open("/", flags)
try:
    trusted(os.fstat(descriptor), "root directory")
    for component in root.strip("/").split("/"):
        child = os.open(component, flags, dir_fd=descriptor)
        os.close(descriptor)
        descriptor = child
        trusted(os.fstat(descriptor), "runtime ancestor")
    try:
        metadata = os.stat("venv", dir_fd=descriptor, follow_symlinks=False)
    except FileNotFoundError:
        metadata = None
    if metadata is not None:
        if not stat.S_ISDIR(metadata.st_mode):
            raise RuntimeError("Piper repair refused: managed venv is not a real directory")
        venv = os.open("venv", flags, dir_fd=descriptor)
        try:
            if os.fstat(venv).st_dev != os.fstat(descriptor).st_dev:
                raise RuntimeError("Piper repair refused: managed venv is a mounted filesystem")
            inspect_tree(venv, metadata.st_dev)
            current = os.stat("venv", dir_fd=descriptor, follow_symlinks=False)
            if (current.st_dev, current.st_ino) != (metadata.st_dev, metadata.st_ino):
                raise RuntimeError("Piper repair refused: managed venv changed during inspection")
            if not shutil.rmtree.avoids_symlink_attacks:
                raise RuntimeError("Piper repair refused: safe directory removal is unavailable")
            shutil.rmtree("venv", dir_fd=descriptor)
        finally:
            os.close(venv)
finally:
    os.close(descriptor)
PY
}

piper_venv_usable() {
  [ -x "$PIPER_PY" ] \
    && /usr/bin/timeout 20 "$PIPER_PY" -I -c \
      'import os, sys; sys.exit(0 if sys.prefix != sys.base_prefix and os.path.realpath(sys.prefix) == os.path.realpath(sys.argv[1]) else 1)' \
      "$PIPER_DIR/venv" >/dev/null 2>&1 \
    && /usr/bin/timeout 20 "$PIPER_PY" -I -m pip --version >/dev/null 2>&1
}

ensure_piper_runtime() {
  validate_piper_runtime_tree || { log "Piper executable tree failed ownership/link validation; no existing interpreter was executed"; return 1; }
  if ! python3 --version >/dev/null 2>&1; then
    log "Piper install failed: python3 is installed but not executable or broken"
    return 1
  fi
  if piper_venv_usable && [ -x "$PIPER_BIN" ] && /usr/bin/timeout 20 "$PIPER_PY" -I -m piper -h >/dev/null 2>&1 \
    && /usr/bin/timeout 20 "$PIPER_PY" -I "$PIPER_DEPENDENCY_CHECK" --requirements "$PIPER_REQUIREMENTS" --quiet \
    && /usr/bin/timeout 20 "$PIPER_PY" -I -m pip check >/dev/null 2>&1; then
    return 0
  fi
  if [ -x "$PIPER_PY" ]; then
    # Only package names and version mismatches are emitted by this checker.
    /usr/bin/timeout 20 "$PIPER_PY" -I "$PIPER_DEPENDENCY_CHECK" --requirements "$PIPER_REQUIREMENTS" >> "$LOG_FILE" 2>&1 || true
  fi
  if ! command -v python3 >/dev/null 2>&1; then
    log "Piper install failed: python3 missing"
    return 1
  fi
  local environment_helper
  local -a replacement_args=()
  environment_helper="/usr/local/bin/sls_mass_notify/sls_piper_environment.py"
  [ -r "$environment_helper" ] || { log "The signed Piper replacement helper is missing; no active packages were changed."; return 1; }
  [ "${PIPER_WORKERS_PAUSED:-0}" != 1 ] || replacement_args+=(--workers-paused)
  /usr/bin/python3 -I "$environment_helper" "${replacement_args[@]}" >> "$LOG_FILE" 2>&1 || {
    log "Piper replacement did not complete. Review the specific namespace, dependency, activation or recovery error above. No in-place package upgrade was attempted."
    return 1
  }
  PIPER_ARTIFACTS_CHANGED=1
  piper_venv_usable && /usr/bin/timeout 20 "$PIPER_PY" -I -m piper -h >/dev/null 2>&1 \
    && /usr/bin/timeout 20 "$PIPER_PY" -I "$PIPER_DEPENDENCY_CHECK" --requirements "$PIPER_REQUIREMENTS" >> "$LOG_FILE" 2>&1 \
    && /usr/bin/timeout 20 "$PIPER_PY" -I -m pip check >/dev/null 2>&1

}

install_piper_wrapper() {
  local expected current wrapper_tmp
  expected="$(cat <<'EOF'
#!/bin/sh
PIPER_BIN="/usr/local/bin/sls_mass_notify/piper/venv/bin/piper"
PIPER_PY="/usr/local/bin/sls_mass_notify/piper/venv/bin/python"
if [ -x "$PIPER_BIN" ]; then
  exec "$PIPER_BIN" "$@"
fi
if [ -x "$PIPER_PY" ] && [ -r "$PIPER_BIN" ]; then
  exec "$PIPER_PY" "$PIPER_BIN" "$@"
fi
if [ -x "$PIPER_PY" ]; then
  exec "$PIPER_PY" -m piper "$@"
fi
echo "Piper TTS binary is not installed or not executable: $PIPER_BIN" >&2
exit 126
EOF
)"
  if [ -e /usr/local/bin/piper ] || [ -L /usr/local/bin/piper ]; then
    if [ -L /usr/local/bin/piper ]; then
      case "$(readlink /usr/local/bin/piper 2>/dev/null || true)" in
        /usr/local/bin/sls_mass_notify/piper/venv/bin/piper|/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper) ;;
        *) printf '%s\n' '/usr/local/bin/piper belongs to another application; refusing to overwrite it.' >&2; return 1 ;;
      esac
    elif ! grep -Eq 'sls_mass_notify/piper|SLS_Mass_Notifications_Plugin/piper' /usr/local/bin/piper 2>/dev/null; then
      printf '%s\n' '/usr/local/bin/piper belongs to another application; refusing to overwrite it.' >&2
      return 1
    fi
  fi
  if [ -f /usr/local/bin/piper ] && [ ! -L /usr/local/bin/piper ] \
    && [ "$(stat -c '%U:%G' /usr/local/bin/piper 2>/dev/null || true)" = "root:root" ] \
    && [ "$(stat -c '%a' /usr/local/bin/piper 2>/dev/null || true)" = "755" ]; then
    current="$(cat /usr/local/bin/piper 2>/dev/null || true)"
    if [ "$current" = "$expected" ]; then
      return 0
    fi
  fi
  wrapper_tmp="$(mktemp /usr/local/bin/.sls-piper.XXXXXX)" || return 1
  if ! printf '%s\n' "$expected" > "$wrapper_tmp" \
    || ! chmod 0755 "$wrapper_tmp" \
    || ! chown root:root "$wrapper_tmp" \
    || ! mv -f "$wrapper_tmp" /usr/local/bin/piper; then
    rm -f "$wrapper_tmp"
    return 1
  fi
  PIPER_ARTIFACTS_CHANGED=1
}

install_piper_compatibility_path() {
  local result
  result="$(
  /usr/bin/python3 -I - <<'PY'
import os
import secrets
import stat

directory_flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
root_fd = os.open("/", directory_flags)
changed = False
piper_fd = -1
venv_fd = -1
bin_fd = -1
temporary = ""

def descend(parent_fd, component):
    return os.open(component, directory_flags, dir_fd=parent_fd)

try:
    current = root_fd
    for component in ("var", "lib", "asterisk", "SLS_Mass_Notifications_Plugin", "piper"):
        next_fd = descend(current, component)
        if current != root_fd:
            os.close(current)
        current = next_fd
    piper_fd = current
    entries = set(os.listdir(piper_fd))
    if "venv" not in entries:
        os.mkdir("venv", 0o755, dir_fd=piper_fd)
        changed = True
    venv_fd = descend(piper_fd, "venv")
    try:
        entries = set(os.listdir(venv_fd))
        if entries - {"bin"}:
            raise RuntimeError("Piper compatibility path contains an unexpected entry")
        if "bin" not in entries:
            os.mkdir("bin", 0o755, dir_fd=venv_fd)
            changed = True
        bin_fd = descend(venv_fd, "bin")
        try:
            entries = set(os.listdir(bin_fd))
            if entries - {"piper"}:
                raise RuntimeError("Piper compatibility path contains an unexpected entry")
            if "piper" in entries:
                metadata = os.stat("piper", dir_fd=bin_fd, follow_symlinks=False)
                if not stat.S_ISLNK(metadata.st_mode) or os.readlink("piper", dir_fd=bin_fd) != "/usr/local/bin/piper":
                    raise RuntimeError("Piper compatibility path contains an untrusted layout")
            else:
                temporary = ".piper." + secrets.token_hex(8)
                os.symlink("/usr/local/bin/piper", temporary, dir_fd=bin_fd)
                os.replace(temporary, "piper", src_dir_fd=bin_fd, dst_dir_fd=bin_fd)
                temporary = ""
                changed = True
            for descriptor in (venv_fd, bin_fd):
                metadata = os.fstat(descriptor)
                if metadata.st_uid != 0 or metadata.st_gid != 0 or stat.S_IMODE(metadata.st_mode) != 0o755:
                    changed = True
                os.fchown(descriptor, 0, 0)
                os.fchmod(descriptor, 0o755)
            os.chown("piper", 0, 0, dir_fd=bin_fd, follow_symlinks=False)
        finally:
            if temporary and bin_fd >= 0:
                try:
                    os.unlink(temporary, dir_fd=bin_fd)
                except FileNotFoundError:
                    pass
            if bin_fd >= 0:
                os.close(bin_fd)
    finally:
        if venv_fd >= 0:
            os.close(venv_fd)
        if piper_fd >= 0:
            os.close(piper_fd)
finally:
    os.close(root_fd)
print("changed" if changed else "unchanged")
PY
  )" || return 1
  if [ "$result" = "changed" ]; then
    PIPER_ARTIFACTS_CHANGED=1
  elif [ "$result" != "unchanged" ]; then
    printf '%s\n' 'Piper compatibility repair returned an invalid result.' >&2
    return 1
  fi
}

validate_piper_runtime_tree() {
  PIPER_PERMISSION_ROOT="$PIPER_DIR" /usr/bin/python3 -I - <<'PYGUARD'
import os
from pathlib import Path
import re
import stat
import time

root = Path(os.environ['PIPER_PERMISSION_ROOT'])
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | os.O_NOFOLLOW
count = 0
deadline = time.monotonic() + 15

def protected(info, label):
    sticky_ancestor = label == 'runtime ancestor' and info.st_mode & stat.S_ISVTX
    if info.st_uid != 0 or (not stat.S_ISLNK(info.st_mode) and info.st_mode & 0o022 and not sticky_ancestor):
        raise RuntimeError('Piper runtime is not root-owned and protected: ' + label)

def system_python(path):
    # Only distribution-owned Python interpreter links outside this venv.
    for unused in range(8):
        if not re.fullmatch(r'/usr/bin/python3(?:\.[0-9]+)?', path):
            raise RuntimeError('unexpected external Piper interpreter link')
        parent = os.open('/usr/bin', flags)
        try:
            protected(os.fstat(parent), 'distribution interpreter directory')
            info = os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)
            protected(info, path)
            if stat.S_ISLNK(info.st_mode):
                target = os.readlink(Path(path).name, dir_fd=parent)
                path = target if target.startswith('/') else '/usr/bin/' + target
                continue
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise RuntimeError('unsafe distribution Python interpreter')
            return
        finally: os.close(parent)
    raise RuntimeError('Piper interpreter link cycle')

def visit(fd, relative=''):
    global count
    protected(os.fstat(fd), relative or 'runtime directory')
    for name in os.listdir(fd):
        count += 1
        if count > 50000 or time.monotonic() > deadline:
            raise RuntimeError('Piper runtime inspection exceeded safety limits')
        rel = relative + '/' + name if relative else name
        link_rel = re.sub(r'^\.replacement-[a-f0-9]{24}/', '', rel)
        info = os.stat(name, dir_fd=fd, follow_symlinks=False)
        protected(info, rel)
        if stat.S_ISDIR(info.st_mode):
            child = os.open(name, flags, dir_fd=fd)
            try:
                current = os.fstat(child)
                if (current.st_dev,current.st_ino) != (info.st_dev,info.st_ino):
                    raise RuntimeError('Piper runtime directory changed')
                visit(child,rel)
            finally: os.close(child)
        elif stat.S_ISREG(info.st_mode):
            if info.st_nlink != 1:
                raise RuntimeError('Piper runtime contains a hard-linked file: ' + rel)
        elif stat.S_ISLNK(info.st_mode):
            target = os.readlink(name, dir_fd=fd)
            if link_rel == 'venv/lib64' and target == 'lib': continue
            if re.fullmatch(r'venv/bin/python(?:3(?:\.[0-9]+)?)?',link_rel):
                if target == 'python3' and link_rel != 'venv/bin/python3': continue
                system_python(target)
                continue
            raise RuntimeError('Piper runtime contains an unexpected symbolic link: ' + rel)
        else: raise RuntimeError('Piper runtime contains an unsupported entry: ' + rel)

fd = os.open('/',flags)
try:
    for part in root.parts[1:]:
        child = os.open(part,flags,dir_fd=fd)
        os.close(fd); fd=child
        protected(os.fstat(fd), 'runtime ancestor')
    visit(fd)
finally: os.close(fd)
PYGUARD
}

secure_piper_runtime_tree() {
  # Never turn service-owned executable bytes into root-trusted code. Refuse
  # the entire tree before metadata changes or interpreter execution.
  validate_piper_runtime_tree || return 1
  PIPER_PERMISSION_ROOT="$PIPER_DIR" /usr/bin/python3 -I - <<'PY'
import os
import stat
import re
root = os.environ["PIPER_PERMISSION_ROOT"]
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | os.O_NOFOLLOW
root_fd = os.open(root, flags)
def secure_tree(fd, relative=""):
    for name in os.listdir(fd):
        before = os.stat(name,dir_fd=fd,follow_symlinks=False)
        rel = relative + '/' + name if relative else name
        if stat.S_ISLNK(before.st_mode): continue
        child = os.open(name,os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK | (os.O_DIRECTORY if stat.S_ISDIR(before.st_mode) else 0),dir_fd=fd)
        try:
            info = os.fstat(child)
            current = os.stat(name,dir_fd=fd,follow_symlinks=False)
            if ((info.st_dev,info.st_ino) != (before.st_dev,before.st_ino)
                    or (info.st_dev,info.st_ino) != (current.st_dev,current.st_ino)
                    or info.st_uid != 0 or info.st_mode & 0o022):
                raise RuntimeError('Piper runtime entry changed during permission repair')
            if stat.S_ISDIR(info.st_mode): secure_tree(child,rel)
            elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                os.fchmod(child,0o600 if rel == '.replacement.lock' else (0o755 if re.sub(r'^\.replacement-[a-f0-9]{24}/', '', rel).startswith('venv/bin/') else 0o644))
            else: raise RuntimeError('unsafe Piper runtime permission target')
        finally: os.close(child)
    info = os.fstat(fd)
    if info.st_uid != 0 or info.st_mode & 0o022: raise RuntimeError('unsafe Piper runtime directory')
    os.fchmod(fd,0o700 if re.fullmatch(r'\.replacement-[a-f0-9]{24}',relative) else 0o755)
try: secure_tree(root_fd)
finally: os.close(root_fd)
PY
}

secure_piper_voice_tree() {
  PIPER_VOICE_PERMISSION_ROOT="$VOICE_DIR" /usr/bin/python3 -I - <<'PY'
import os
import pwd
import stat

root = os.environ["PIPER_VOICE_PERMISSION_ROOT"]
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
root_fd = os.open(root, flags)
account = pwd.getpwnam("asterisk")
try:
    os.fchown(root_fd, account.pw_uid, account.pw_gid)
    os.fchmod(root_fd, 0o755)
    for name in os.listdir(root_fd):
        metadata = os.stat(name, dir_fd=root_fd, follow_symlinks=False)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise RuntimeError(f"unsupported Piper voice entry: {name}")
        child_fd = os.open(
            name,
            os.O_RDONLY | os.O_CLOEXEC | os.O_NONBLOCK | os.O_NOFOLLOW,
            dir_fd=root_fd,
        )
        try:
            opened = os.fstat(child_fd)
            current = os.stat(name, dir_fd=root_fd, follow_symlinks=False)
            if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                    or (opened.st_dev, opened.st_ino) != (metadata.st_dev, metadata.st_ino)
                    or (opened.st_dev, opened.st_ino) != (current.st_dev, current.st_ino)):
                raise RuntimeError('unsafe Piper voice permission target')
            os.fchown(child_fd, account.pw_uid, account.pw_gid)
            os.fchmod(child_fd, 0o644)
        finally:
            os.close(child_fd)
finally:
    os.close(root_fd)
PY
}

repair_piper_permissions_only() {
  [ -d "$PIPER_DIR" ] && [ ! -L "$PIPER_DIR" ] && [ -x "$PIPER_BIN" ] || return 1
  secure_piper_runtime_tree
  if [ -d "$VOICE_DIR" ]; then
    [ ! -L /var/lib/asterisk/SLS_Mass_Notifications_Plugin ] \
      && [ ! -L /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper ] \
      && [ ! -L "$VOICE_DIR" ] || return 1
    secure_piper_voice_tree
  fi
  install_piper_wrapper
  install_piper_compatibility_path
}

PIPER_ACTION="all"
PIPER_WORKERS_PAUSED=0
for option in "$@"; do
  case "$option" in
    --runtime-only|--repair-permissions-only)
      [ "$PIPER_ACTION" = all ] || exit 2
      PIPER_ACTION="${option#--}" ;;
    --workers-paused) PIPER_WORKERS_PAUSED=1 ;;
    *) exit 2 ;;
  esac
done
if [ "$PIPER_ACTION" = repair-permissions-only ]; then
  repair_piper_permissions_only
  exit $?
fi

if [ -L "$PIPER_DIR" ] \
  || [ -L /var/lib/asterisk/SLS_Mass_Notifications_Plugin ] \
  || [ -L /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper ] \
  || [ -L "$VOICE_DIR" ]; then
  printf '%s\n' 'Piper installation path must not contain a symbolic-link directory.' >&2
  exit 1
fi
mkdir -p "$PIPER_DIR"
mkdir -p "$VOICE_DIR"

if ! ensure_piper_runtime; then
  printf 'Piper TTS runtime could not be installed. Check %s for details.\n' "$LOG_FILE" >&2
  exit 1
fi

if [ "$PIPER_ACTION" = runtime-only ]; then
  secure_piper_runtime_tree
  install_piper_wrapper
  install_piper_compatibility_path
  exit 0
fi

voice_revision="e21c7de8d4eab79b902f0d61e662b3f21664b8d2"
base="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/en/en_US"
declare -A files=(
  ["en_US-lessac-low.onnx"]="$base/lessac/low/en_US-lessac-low.onnx"
  ["en_US-lessac-low.onnx.json"]="$base/lessac/low/en_US-lessac-low.onnx.json"
  ["en_US-lessac-medium.onnx"]="$base/lessac/medium/en_US-lessac-medium.onnx"
  ["en_US-lessac-medium.onnx.json"]="$base/lessac/medium/en_US-lessac-medium.onnx.json"
  ["en_US-amy-low.onnx"]="$base/amy/low/en_US-amy-low.onnx"
  ["en_US-amy-low.onnx.json"]="$base/amy/low/en_US-amy-low.onnx.json"
  ["en_US-ryan-low.onnx"]="$base/ryan/low/en_US-ryan-low.onnx"
  ["en_US-ryan-low.onnx.json"]="$base/ryan/low/en_US-ryan-low.onnx.json"
  ["es_ES-davefx-medium.onnx"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/es/es_ES/davefx/medium/es_ES-davefx-medium.onnx"
  ["es_ES-davefx-medium.onnx.json"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/es/es_ES/davefx/medium/es_ES-davefx-medium.onnx.json"
  ["fr_FR-siwis-medium.onnx"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/fr/fr_FR/siwis/medium/fr_FR-siwis-medium.onnx"
  ["fr_FR-siwis-medium.onnx.json"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/fr/fr_FR/siwis/medium/fr_FR-siwis-medium.onnx.json"
  ["de_DE-thorsten-low.onnx"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/de/de_DE/thorsten/low/de_DE-thorsten-low.onnx"
  ["de_DE-thorsten-low.onnx.json"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/de/de_DE/thorsten/low/de_DE-thorsten-low.onnx.json"
  ["pt_BR-faber-medium.onnx"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/pt/pt_BR/faber/medium/pt_BR-faber-medium.onnx"
  ["pt_BR-faber-medium.onnx.json"]="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/pt/pt_BR/faber/medium/pt_BR-faber-medium.onnx.json"
)
declare -A hashes=(
  ["en_US-lessac-low.onnx"]="f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375"
  ["en_US-lessac-low.onnx.json"]="45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a"
  ["en_US-lessac-medium.onnx"]="5efe09e69902187827af646e1a6e9d269dee769f9877d17b16b1b46eeaaf019f"
  ["en_US-lessac-medium.onnx.json"]="efe19c417bed055f2d69908248c6ba650fa135bc868b0e6abb3da181dab690a0"
  ["en_US-amy-low.onnx"]="a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3"
  ["en_US-amy-low.onnx.json"]="2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb"
  ["en_US-ryan-low.onnx"]="8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf"
  ["en_US-ryan-low.onnx.json"]="b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60"
  ["es_ES-davefx-medium.onnx"]="6658b03b1a6c316ee4c265a9896abc1393353c2d9e1bca7d66c2c442e222a917"
  ["es_ES-davefx-medium.onnx.json"]="0e0dda87c732f6f38771ff274a6380d9252f327dca77aa2963d5fbdf9ec54842"
  ["fr_FR-siwis-medium.onnx"]="641d1ab097da2b81128c076810edb052b385decc8be3381814802a64a73baf99"
  ["fr_FR-siwis-medium.onnx.json"]="39479916c2db192b5ac9764daddd0c744d83e023ad890c6976c0633ae4df8959"
  ["de_DE-thorsten-low.onnx"]="9ac27fad17cec5c1a791161976a64f026f16fc058b400b1fea62565b8b2cf375"
  ["de_DE-thorsten-low.onnx.json"]="df38e892ed949f62d0c40978bc666de0b341c5f7921840ae7c72f4df8e8ffa05"
  ["pt_BR-faber-medium.onnx"]="858555e3a064209c57088fe6bd70c4c3dc54d03eaa00c45d5ecaf43a33f95aa7"
  ["pt_BR-faber-medium.onnx.json"]="7e694de195ae3fc36dd732c445eb04fb49b649854893cb5506b978f0d50a1d6f"
)

failures=()
for file in \
  en_US-lessac-low.onnx en_US-lessac-low.onnx.json \
  en_US-lessac-medium.onnx en_US-lessac-medium.onnx.json \
  en_US-amy-low.onnx en_US-amy-low.onnx.json \
  en_US-ryan-low.onnx en_US-ryan-low.onnx.json \
  es_ES-davefx-medium.onnx \
  es_ES-davefx-medium.onnx.json \
  fr_FR-siwis-medium.onnx \
  fr_FR-siwis-medium.onnx.json \
  de_DE-thorsten-low.onnx \
  de_DE-thorsten-low.onnx.json \
  pt_BR-faber-medium.onnx \
  pt_BR-faber-medium.onnx.json
do
  target="$VOICE_DIR/$file"
  if [ -f "$target" ] && printf '%s  %s\n' "${hashes[$file]}" "$target" | sha256sum -c - >/dev/null 2>&1; then
    continue
  fi
  log "Downloading Piper voice file $file"
  if ! download_file "${files[$file]}" "$target" "${hashes[$file]}"; then
    failures+=("$file")
    log "Piper voice download failed for $file"
  else
    PIPER_ARTIFACTS_CHANGED=1
  fi
done

secure_piper_runtime_tree
secure_piper_voice_tree

install_piper_wrapper

if [ -x "$PIPER_BIN" ]; then
  install_piper_compatibility_path
fi

if [ "${#failures[@]}" -gt 0 ]; then
  printf 'Failed Piper voice downloads: %s\n' "${failures[*]}" >&2
  exit 1
fi

if [ "$PIPER_ARTIFACTS_CHANGED" -eq 1 ]; then
  log "Piper runtime or voice artifacts installed or repaired successfully"
fi
exit 0
