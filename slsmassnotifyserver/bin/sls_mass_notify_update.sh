#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -euo pipefail

umask 027

CONFIG_JSON_FILE="${CONFIG_JSON_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}"
CONFIG_LOADER="${CONFIG_LOADER:-/usr/local/bin/sls_mass_notify/sls_config.py}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-status.json}"
LOG_FILE="${LOG_FILE:-/var/log/sls_mass_notify.log}"
LOCK_FILE="${LOCK_FILE:-/run/lock/sls-mass-notify-update.lock}"
CURRENT_VERSION="${SLS_MASS_NOTIFY_CURRENT_VERSION:-0.0.5-beta}"

GITHUB_UPDATES_ENABLED="0"
readonly GITHUB_UPDATES_REPOSITORY="vipgabe09267/SouthlandServers_Mass_Notify_server"

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

write_status() {
  local json="$1"
  STATUS_FILE_PATH="$STATUS_FILE" STATUS_JSON="$json" /usr/bin/python3 - <<'PY'
import fcntl
import json
import os
import tempfile

path = os.environ["STATUS_FILE_PATH"]
payload = json.loads(os.environ["STATUS_JSON"])
directory = os.path.dirname(path)
os.makedirs(directory, mode=0o750, exist_ok=True)
lock_path = "/run/lock/sls-mass-notify-update-status.lock"
with open(lock_path, "a+", encoding="utf-8") as lock_handle:
    fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX)
    fd, temporary = tempfile.mkstemp(prefix=".update-status.", dir=directory)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(temporary, 0o640)
    try:
        import pwd
        account = pwd.getpwnam("asterisk")
        os.chown(temporary, account.pw_uid, account.pw_gid)
    except (KeyError, PermissionError):
        pass
    os.replace(temporary, path)
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)
    fcntl.flock(lock_handle.fileno(), fcntl.LOCK_UN)
PY
}

load_update_config() {
  local dump_file
  local key
  local value
  dump_file="$(mktemp /tmp/sls_mass_notify_update_config.XXXXXX)" || return 1
  if [ ! -x "$CONFIG_LOADER" ] || ! /usr/bin/python3 "$CONFIG_LOADER" "$CONFIG_JSON_FILE" > "$dump_file" 2>>"$LOG_FILE"; then
    rm -f "$dump_file"
    return 1
  fi
  while IFS= read -r -d '' key && IFS= read -r -d '' value; do
    case "$key" in
      GITHUB_UPDATES_ENABLED)
        printf -v "$key" '%s' "$value"
        ;;
    esac
  done < "$dump_file"
  rm -f "$dump_file"
}

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  log "Automatic update refused because it was not started as root"
  exit 1
fi

mkdir -p "$(dirname "$LOCK_FILE")"
exec 9>"$LOCK_FILE"
chmod 0600 "$LOCK_FILE" 2>/dev/null || true
chown root:root "$LOCK_FILE" 2>/dev/null || true
if ! flock -n 9; then
  log "Automatic update check skipped because another update process is running"
  exit 0
fi

if ! load_update_config; then
  write_status "$(python3 - <<'PY'
import json
from datetime import datetime, timezone
print(json.dumps({"checked_at": datetime.now(timezone.utc).astimezone().isoformat(), "update_available": False, "message": "Automatic update check failed: central config is invalid or unavailable."}, separators=(",", ":")))
PY
)"
  exit 0
fi

if [ "$GITHUB_UPDATES_ENABLED" != "1" ]; then
  write_status "$(python3 - <<'PY'
import json
from datetime import datetime, timezone
print(json.dumps({"checked_at": datetime.now(timezone.utc).astimezone().isoformat(), "update_available": False, "message": "Automatic GitHub updates are disabled."}, separators=(",", ":")))
PY
)"
  exit 0
fi

release_json="$(CURRENT_VERSION="$CURRENT_VERSION" REPOSITORY="$GITHUB_UPDATES_REPOSITORY" python3 - <<'PY'
import json
import os
import re
import urllib.request
from datetime import datetime, timezone

repo = os.environ.get("REPOSITORY", "")
current = os.environ.get("CURRENT_VERSION", "0.0.5-beta")
now = datetime.now(timezone.utc).astimezone().isoformat()
if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
    print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "message": "Configured GitHub repository is invalid."}, separators=(",", ":")))
    raise SystemExit(0)

def norm(value):
    value = re.sub(r"^slsmassnotifyserver[-_]", "", str(value or ""), flags=re.I)
    return re.sub(r"^[vV]", "", value).strip()

def version_key(value):
    normalized = norm(value)
    numbers = [int(part) for part in re.findall(r"\d+", normalized)[:4]]
    return tuple((numbers + [0, 0, 0, 0])[:4])

try:
    request = urllib.request.Request(
        f"https://api.github.com/repos/{repo}/releases",
        headers={"Accept": "application/vnd.github+json", "User-Agent": "SouthlandServers-Mass-Notifications-Updater/0.0.5-beta"},
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        releases = json.load(response)
except Exception as exc:
    print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "message": f"GitHub update check failed: {exc}"}, separators=(",", ":")))
    raise SystemExit(0)

candidates = []
for release in releases if isinstance(releases, list) else []:
    if not isinstance(release, dict) or release.get("draft"):
        continue
    tag = str(release.get("tag_name") or "")
    if not re.fullmatch(r"slsmassnotifyserver-\d+\.\d+\.\d+-beta", tag):
        continue
    for asset in release.get("assets") or []:
        if not isinstance(asset, dict):
            continue
        expected_name = tag + ".tgz"
        if str(asset.get("name") or "") != expected_name:
            continue
        digest = str(asset.get("digest") or "")
        if not re.fullmatch(r"sha256:[0-9a-fA-F]{64}", digest):
            continue
        candidates.append((version_key(tag), tag, str(asset.get("browser_download_url") or ""), digest.split(":", 1)[1].lower()))

candidates.sort(reverse=True)
if not candidates:
    print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "latest_version": current, "message": "No signed-digest beta release asset was found."}, separators=(",", ":")))
    raise SystemExit(0)

_, tag, tgz_url, sha256 = candidates[0]
available = version_key(tag) > version_key(current)
print(json.dumps({
    "ok": True,
    "checked_at": now,
    "update_available": available,
    "latest_version": norm(tag),
    "tag_name": tag,
    "tgz_url": tgz_url,
    "sha256": sha256,
    "installer_url": f"https://raw.githubusercontent.com/{repo}/{tag}/tools/install_release.sh",
    "message": "Update available." if available else "Installed package is current.",
}, separators=(",", ":")))
PY
)"

write_status "$release_json"
update_available="$(printf '%s' "$release_json" | python3 -c 'import json,sys; print("1" if json.load(sys.stdin).get("update_available") else "0")' 2>/dev/null || printf '0')"
[ "$update_available" = "1" ] || exit 0

readarray -t release_values < <(printf '%s' "$release_json" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("tgz_url", "")); print(d.get("sha256", "")); print(d.get("installer_url", "")); print(d.get("latest_version", ""))')
tgz_url="${release_values[0]:-}"
sha256="${release_values[1]:-}"
installer_url="${release_values[2]:-}"
latest="${release_values[3]:-}"
if [ -z "$tgz_url" ] || ! [[ "$sha256" =~ ^[0-9a-f]{64}$ ]] || [ -z "$installer_url" ]; then
  log "Automatic update rejected incomplete release metadata"
  exit 0
fi

tmp_script="$(mktemp /tmp/slsmassnotifyserver-auto-install.XXXXXX)" || exit 0
trap 'rm -f "$tmp_script"' EXIT
if ! curl -fsSL --proto '=https' --tlsv1.2 -o "$tmp_script" "$installer_url" >> "$LOG_FILE" 2>&1; then
  log "Automatic update could not download the tagged installer for $latest"
  exit 0
fi
chmod 0700 "$tmp_script"
log "Automatic update installing $latest"
SLS_MASS_NOTIFY_TGZ_URL="$tgz_url" SLS_MASS_NOTIFY_SHA256="$sha256" "$tmp_script" >> "$LOG_FILE" 2>&1 || {
  log "Automatic update install failed for $latest"
  exit 0
}
log "Automatic update completed for $latest"
