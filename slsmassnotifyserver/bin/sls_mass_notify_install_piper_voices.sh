#!/bin/bash
set -euo pipefail

umask 027

VOICE_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices"
PIPER_DIR="/usr/local/bin/sls_mass_notify/piper"
PIPER_BIN="$PIPER_DIR/venv/bin/piper"
PIPER_PY="$PIPER_DIR/venv/bin/python"
LOG_FILE="/var/log/sls_mass_notify.log"

mkdir -p "$PIPER_DIR"
mkdir -p "$VOICE_DIR"
chown root:root "$PIPER_DIR" 2>/dev/null || true
chmod 0755 "$PIPER_DIR" 2>/dev/null || true

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

download_file() {
  local url="$1"
  local target="$2"
  local expected_sha="$3"
  local tmp="${target}.download"

  rm -f "$tmp"
  if command -v curl >/dev/null 2>&1; then
    curl -fL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 900 \
      -A "SouthlandServers-Mass-Notifications-Server/0.0.5-beta" \
      -o "$tmp" "$url"
  elif command -v wget >/dev/null 2>&1; then
    wget --tries=5 --timeout=900 -O "$tmp" "$url"
  else
    log "Piper voice download failed: curl/wget missing"
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

  mv -f "$tmp" "$target"
  chmod 0644 "$target"
  chown asterisk:asterisk "$target" 2>/dev/null || true
  return 0
}

ensure_piper_runtime() {
  if ! python3 --version >> "$LOG_FILE" 2>&1; then
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
  [ -e "$PIPER_BIN" ] && chmod 0755 "$PIPER_BIN" 2>/dev/null || true
  [ -e "$PIPER_PY" ] && chmod 0755 "$PIPER_PY" 2>/dev/null || true
  [ -e "$PIPER_DIR/venv/bin/python3" ] && chmod 0755 "$PIPER_DIR/venv/bin/python3" 2>/dev/null || true
  [ -x "$PIPER_BIN" ] || { [ -x "$PIPER_PY" ] && "$PIPER_PY" -m piper -h >/dev/null 2>&1; }
}

install_piper_wrapper() {
  rm -f /usr/local/bin/piper
  cat > /usr/local/bin/piper <<'EOF'
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
  chmod 0755 /usr/local/bin/piper
}

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
  fi
done

chown -R root:root "$PIPER_DIR" 2>/dev/null || true
find "$PIPER_DIR" -type d -exec chmod 0755 {} + 2>/dev/null || true
chmod 0755 "$PIPER_DIR" 2>/dev/null || true
chown -R asterisk:asterisk "$VOICE_DIR" 2>/dev/null || true
find "$VOICE_DIR" -type f -exec chmod 0644 {} + 2>/dev/null || true
[ -e "$PIPER_BIN" ] && chmod 0755 "$PIPER_BIN" 2>/dev/null || true
[ -e "$PIPER_PY" ] && chmod 0755 "$PIPER_PY" 2>/dev/null || true
[ -e "$PIPER_DIR/venv/bin/python3" ] && chmod 0755 "$PIPER_DIR/venv/bin/python3" 2>/dev/null || true

install_piper_wrapper

if [ -x "$PIPER_BIN" ]; then
  rm -rf /var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv
fi

if [ "${#failures[@]}" -gt 0 ]; then
  printf 'Failed Piper voice downloads: %s\n' "${failures[*]}" >&2
  exit 1
fi

log "Piper voice files installed successfully"
exit 0
