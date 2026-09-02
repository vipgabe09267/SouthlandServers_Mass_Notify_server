#!/bin/bash
set -euo pipefail

umask 027

VOICE_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices"
PIPER_DIR="/usr/local/bin/sls_mass_notify/piper"
PIPER_BIN="$PIPER_DIR/venv/bin/piper"
PIPER_PY="$PIPER_DIR/venv/bin/python"
LOG_FILE="/var/log/sls_mass_notify.log"
PIPER_ARTIFACTS_CHANGED=0

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

download_file() {
  local url="$1"
  local target="$2"
  local expected_sha="$3"
  local tmp

  tmp="$(mktemp /tmp/sls-piper-voice.XXXXXXXX)" || return 1
  if command -v curl >/dev/null 2>&1; then
    if ! curl -fL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 900 \
      -A "SouthlandServers-Mass-Notifications-Server/0.1.1-beta" \
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
    python3 -m json.tool "$tmp" >/dev/null 2>&1 || {
      rm -f "$tmp"
      return 1
    }
  fi

  SLS_PIPER_VOICE_SOURCE="$tmp" SLS_PIPER_VOICE_TARGET="$target" /usr/bin/python3 - <<'PY' || {
import os
import pwd
import secrets
import stat

source = os.environ["SLS_PIPER_VOICE_SOURCE"]
target = os.environ["SLS_PIPER_VOICE_TARGET"]
name = os.path.basename(target)
if target != "/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/" + name \
        or not name.startswith("en_US-") \
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

ensure_piper_runtime() {
  if ! python3 --version >/dev/null 2>&1; then
    log "Piper install failed: python3 is installed but not executable or broken"
    return 1
  fi
  if [ -x "$PIPER_BIN" ] || { [ -x "$PIPER_PY" ] && "$PIPER_PY" -m piper -h >/dev/null 2>&1; }; then
    return 0
  fi
  if ! command -v python3 >/dev/null 2>&1; then
    log "Piper install failed: python3 missing"
    return 1
  fi
  if [ ! -x "$PIPER_DIR/venv/bin/pip" ]; then
    rm -rf "$PIPER_DIR/venv"
    if ! python3 -m venv "$PIPER_DIR/venv" >> "$LOG_FILE" 2>&1; then
      if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update >> "$LOG_FILE" 2>&1 || true
        DEBIAN_FRONTEND=noninteractive apt-get install -y python3-venv python3-pip >> "$LOG_FILE" 2>&1 || true
        python3 -m venv "$PIPER_DIR/venv" >> "$LOG_FILE" 2>&1 || return 1
      else
        return 1
      fi
    fi
  fi
  "$PIPER_DIR/venv/bin/pip" install --upgrade 'pip==26.1.2' 'setuptools==83.0.0' 'wheel==0.47.0' >> "$LOG_FILE" 2>&1 || return 1
  "$PIPER_DIR/venv/bin/pip" install 'piper-tts==1.4.2' >> "$LOG_FILE" 2>&1 || return 1
  PIPER_ARTIFACTS_CHANGED=1
  [ -x "$PIPER_BIN" ] || { [ -x "$PIPER_PY" ] && "$PIPER_PY" -m piper -h >/dev/null 2>&1; }
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
  /usr/bin/python3 - <<'PY'
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

secure_piper_runtime_tree() {
  PIPER_PERMISSION_ROOT="$PIPER_DIR" /usr/bin/python3 - <<'PY'
import os
import stat

root = os.environ["PIPER_PERMISSION_ROOT"]
flags = os.O_RDONLY | os.O_CLOEXEC | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
root_fd = os.open(root, flags)

def secure_tree(directory_fd, relative=""):
    os.fchown(directory_fd, 0, 0)
    os.fchmod(directory_fd, 0o755)
    for name in os.listdir(directory_fd):
        metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        child_relative = f"{relative}/{name}" if relative else name
        if stat.S_ISDIR(metadata.st_mode):
            child_fd = os.open(name, flags, dir_fd=directory_fd)
            try:
                secure_tree(child_fd, child_relative)
            finally:
                os.close(child_fd)
        elif stat.S_ISREG(metadata.st_mode):
            child_fd = os.open(
                name,
                os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0),
                dir_fd=directory_fd,
            )
            try:
                os.fchown(child_fd, 0, 0)
                os.fchmod(child_fd, 0o755 if child_relative.startswith("venv/bin/") else 0o644)
            finally:
                os.close(child_fd)
        elif stat.S_ISLNK(metadata.st_mode):
            os.chown(name, 0, 0, dir_fd=directory_fd, follow_symlinks=False)
        else:
            raise RuntimeError(f"unsupported Piper runtime entry: {child_relative}")

try:
    secure_tree(root_fd)
finally:
    os.close(root_fd)
PY
}

secure_piper_voice_tree() {
  PIPER_VOICE_PERMISSION_ROOT="$VOICE_DIR" /usr/bin/python3 - <<'PY'
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
        if not stat.S_ISREG(metadata.st_mode):
            raise RuntimeError(f"unsupported Piper voice entry: {name}")
        child_fd = os.open(
            name,
            os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0),
            dir_fd=root_fd,
        )
        try:
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

if [ "${1:-}" = "--repair-permissions-only" ]; then
  [ "$#" -eq 1 ] || exit 2
  repair_piper_permissions_only
  exit $?
elif [ "$#" -ne 0 ]; then
  exit 2
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
chown root:root "$PIPER_DIR" 2>/dev/null || true
chmod 0755 "$PIPER_DIR" 2>/dev/null || true

if ! ensure_piper_runtime; then
  printf 'Piper TTS runtime could not be installed. Check %s for details.\n' "$LOG_FILE" >&2
  exit 1
fi

voice_revision="e21c7de8d4eab79b902f0d61e662b3f21664b8d2"
base="https://huggingface.co/rhasspy/piper-voices/resolve/${voice_revision}/en/en_US"
declare -A files=(
  ["en_US-lessac-low.onnx"]="$base/lessac/low/en_US-lessac-low.onnx"
  ["en_US-lessac-low.onnx.json"]="$base/lessac/low/en_US-lessac-low.onnx.json"
  ["en_US-amy-low.onnx"]="$base/amy/low/en_US-amy-low.onnx"
  ["en_US-amy-low.onnx.json"]="$base/amy/low/en_US-amy-low.onnx.json"
  ["en_US-ryan-low.onnx"]="$base/ryan/low/en_US-ryan-low.onnx"
  ["en_US-ryan-low.onnx.json"]="$base/ryan/low/en_US-ryan-low.onnx.json"
)
declare -A hashes=(
  ["en_US-lessac-low.onnx"]="f7d01dde371555732c4c314111ac79672b1a5ce2fc19266ab42178fd8df7f375"
  ["en_US-lessac-low.onnx.json"]="45754dfdebb3b8661c3fc564713772deec6e064feeb5b4e9594857dc7305193a"
  ["en_US-amy-low.onnx"]="a5a91abb7de0f104358a25aded480ddacf1ff0762886325886ec406a2e86aab3"
  ["en_US-amy-low.onnx.json"]="2250a9a605b8dc35a116717fadc5056695dd809e34a15d02f72a0f52d53d3ebb"
  ["en_US-ryan-low.onnx"]="8d21a085cc4c0010f1f3e91d5008c8691277ccfa744eb0d747becd33a3444baf"
  ["en_US-ryan-low.onnx.json"]="b27147e56b0525962609f82f58171f4618cbf17c6fb043d7d724ff28cc4aed60"
)

failures=()
for file in \
  en_US-lessac-low.onnx en_US-lessac-low.onnx.json \
  en_US-amy-low.onnx en_US-amy-low.onnx.json \
  en_US-ryan-low.onnx en_US-ryan-low.onnx.json
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
