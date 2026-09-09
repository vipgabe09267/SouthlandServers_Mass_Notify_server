#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -euo pipefail

umask 027
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

CONFIG_JSON_FILE="${CONFIG_JSON_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}"
CONFIG_LOADER="${CONFIG_LOADER:-/usr/local/bin/sls_mass_notify/sls_config.py}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-status.json}"
UPDATE_PROGRESS_FILE="${UPDATE_PROGRESS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/update-progress.json}"
LOG_FILE="${LOG_FILE:-/var/log/sls_mass_notify.log}"
LOCK_FILE="${LOCK_FILE:-/run/lock/sls-mass-notify-update.lock}"
CURRENT_VERSION="${SLS_MASS_NOTIFY_CURRENT_VERSION:-0.1.4-beta}"

GITHUB_UPDATES_ENABLED="0"
MANUAL_UPDATE="${SLS_MASS_NOTIFY_MANUAL_UPDATE:-0}"
CHECK_ONLY="${SLS_MASS_NOTIFY_CHECK_ONLY:-0}"
readonly GITHUB_UPDATES_REPOSITORY="vipgabe09267/SouthlandServers_Mass_Notify_server"
FAILURE_RECORDED=0
release_work=""

# Open only a root-owned regular file through trusted directories. Creation is
# exclusive; an existing file is never truncated or written before fd identity
# verification. Sticky root-owned /tmp and /run/lock are permitted.
open_root_owned_file() {
  local output_variable="$1" protected_path="$2"
  local protected_identity opened_identity protected_fd
  protected_identity="$(/usr/bin/python3 - "$protected_path" <<'PY'
import os
import stat
import sys

path = sys.argv[1]
parts = path.split("/")[1:]
if not path.startswith("/") or not parts or any(part in {"", ".", ".."} for part in parts):
    raise SystemExit(1)
directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW
parent = os.open("/", directory_flags)
try:
    for component in parts[:-1]:
        child = os.open(component, directory_flags, dir_fd=parent)
        os.close(parent)
        parent = child
        metadata = os.fstat(parent)
        if metadata.st_uid != 0 or (metadata.st_mode & 0o022 and not metadata.st_mode & stat.S_ISVTX):
            raise SystemExit(1)
    flags = os.O_WRONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    try:
        descriptor = os.open(parts[-1], flags | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=parent)
    except FileExistsError:
        descriptor = os.open(parts[-1], flags, dir_fd=parent)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_nlink != 1 or metadata.st_mode & 0o022:
            raise SystemExit(1)
        print(f"{metadata.st_dev}:{metadata.st_ino}")
    finally:
        os.close(descriptor)
finally:
    os.close(parent)
PY
)" || return 1
  exec {protected_fd}>>"$protected_path" || return 1
  opened_identity="$(stat -Lc '%d:%i' "/proc/${BASHPID}/fd/$protected_fd" 2>/dev/null)" || opened_identity=""
  if [ "$opened_identity" != "$protected_identity" ]; then
    exec {protected_fd}>&-
    return 1
  fi
  chmod 0600 "/proc/${BASHPID}/fd/$protected_fd" || {
    exec {protected_fd}>&-
    return 1
  }
  printf -v "$output_variable" '%s' "$protected_fd"
}

log() {
  printf '%s: %s\n' "$(date)" "$*" >> "$LOG_FILE" 2>/dev/null || true
}

write_manual_progress() {
  [ "$MANUAL_UPDATE" = "1" ] || return 0
  local state="$1"
  local message="$2"
  UPDATE_PROGRESS_FILE="$UPDATE_PROGRESS_FILE" UPDATE_STATE="$state" UPDATE_MESSAGE="$message" UPDATE_ERROR_CATEGORY="${3:-}" UPDATE_EXIT_CODE="${4:-0}" /usr/bin/python3 - <<'PY'
import json
import os
import pwd
import tempfile
from datetime import datetime, timezone

path = os.environ["UPDATE_PROGRESS_FILE"]
directory = os.path.dirname(path)
os.makedirs(directory, mode=0o750, exist_ok=True)
payload = {
    "state": os.environ["UPDATE_STATE"],
    "message": os.environ["UPDATE_MESSAGE"][:300],
    "updated_at": datetime.now(timezone.utc).isoformat(),
    "error_category": os.environ["UPDATE_ERROR_CATEGORY"],
    "exit_code": int(os.environ["UPDATE_EXIT_CODE"]),
}
fd, temporary = tempfile.mkstemp(prefix=".update-progress.", dir=directory)
with os.fdopen(fd, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, separators=(",", ":"))
    handle.write("\n")
os.chmod(temporary, 0o640)
account = pwd.getpwnam("asterisk")
os.chown(temporary, account.pw_uid, account.pw_gid)
os.replace(temporary, path)
PY
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

# All operational failures are nonzero, including check-only requests. Messages
# exposed to the UI are fixed categories, never raw subprocess/network output.
fail_update() {
  local category="$1" message="$2" status="${3:-1}"
  [[ "$status" =~ ^[0-9]+$ ]] && [ "$status" -gt 0 ] && [ "$status" -lt 256 ] || status=1
  FAILURE_RECORDED=1
  log "Update failed [$category] (exit $status): $message"
  write_status "$(CURRENT_VERSION="$CURRENT_VERSION" FAILURE_CATEGORY="$category" FAILURE_MESSAGE="$message" FAILURE_EXIT_CODE="$status" python3 - <<'PY'
import json
import os
from datetime import datetime, timezone
print(json.dumps({"ok": False, "checked_at": datetime.now(timezone.utc).isoformat(), "update_available": False, "latest_version": os.environ["CURRENT_VERSION"], "message": os.environ["FAILURE_MESSAGE"], "error_category": os.environ["FAILURE_CATEGORY"], "exit_code": int(os.environ["FAILURE_EXIT_CODE"])}, separators=(",", ":")))
PY
)" || true
  write_manual_progress "failed" "$message" "$category" "$status" || true
  exit "$status"
}

finish_update() {
  local status=$?
  trap - EXIT
  if [ -n "$release_work" ]; then
    rm -f "$release_work/install_release.sh" "$release_work/release-manifest.json" "$release_work/release-manifest.sig" "$release_work/package.tgz"
    rmdir "$release_work" 2>/dev/null || true
  fi
  if [ "$status" -ne 0 ] && [ "$FAILURE_RECORDED" -ne 1 ]; then
    fail_update "process_failed" "The update process stopped unexpectedly. Review Notification Logs for details." "$status"
  fi
  exit "$status"
}
trap finish_update EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  log "Automatic update refused because it was not started as root"
  exit 1
fi

mkdir -p "$(dirname "$LOCK_FILE")"
UPDATE_LOCK_FD=""
open_root_owned_file UPDATE_LOCK_FD "$LOCK_FILE" || fail_update "process_failed" "The protected update lock could not be opened safely."
exec 9>&"$UPDATE_LOCK_FD"
exec {UPDATE_LOCK_FD}>&-
if ! flock -n 9; then
  log "Automatic update check skipped because another update process is running"
  if [ "$MANUAL_UPDATE" = "1" ]; then
    fail_update "lock_busy" "Another update process is already running. Try again after it finishes." 75
  fi
  exit 0
fi

if ! load_update_config; then
  fail_update "protected_config_validation_failed" "The protected central configuration is invalid or unavailable." 2
fi

release_json="$(CURRENT_VERSION="$CURRENT_VERSION" REPOSITORY="$GITHUB_UPDATES_REPOSITORY" python3 - <<'PY'
import json
import os
import re
import urllib.request
from datetime import datetime, timezone

repo = os.environ.get("REPOSITORY", "")
current = os.environ.get("CURRENT_VERSION", "0.1.4-beta")
now = datetime.now(timezone.utc).astimezone().isoformat()
if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
    print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "latest_version": current, "message": "Configured GitHub repository is invalid."}, separators=(",", ":")))
    raise SystemExit(0)

def norm(value):
    value = re.sub(r"^slsmassnotifyserver[-_]", "", str(value or ""), flags=re.I)
    return re.sub(r"^[vV]", "", value).strip()

def version_key(value):
    normalized = norm(value)
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)(-beta)?", normalized)
    if not match:
        return (0, 0, 0, -1)
    major, minor, patch = (int(match.group(index)) for index in (1, 2, 3))
    return (major, minor, patch, 0 if match.group(4) else 1)

try:
    request = urllib.request.Request(
        f"https://api.github.com/repos/{repo}/releases",
        headers={"Accept": "application/vnd.github+json", "User-Agent": "SouthlandServers-Mass-Notifications-Updater/0.1.4-beta"},
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        releases = json.load(response)
except Exception:
    print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "latest_version": current, "message": "The verified release feed could not be checked."}, separators=(",", ":")))
    raise SystemExit(0)

candidates = []
for release in releases if isinstance(releases, list) else []:
    if not isinstance(release, dict) or release.get("draft"):
        continue
    tag = str(release.get("tag_name") or "")
    if not re.fullmatch(r"slsmassnotifyserver-\d+\.\d+\.\d+(?:-beta)?", tag):
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
    print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "latest_version": current, "message": "No release asset with verified SHA-256 metadata was found."}, separators=(",", ":")))
    raise SystemExit(0)

_, tag, tgz_url, sha256 = candidates[0]
available = version_key(tag) > version_key(current)
installer_commit = ""
if available:
    try:
        commit_request = urllib.request.Request(
            f"https://api.github.com/repos/{repo}/commits/{tag}",
            headers={"Accept": "application/vnd.github+json", "User-Agent": "SLS-Mass-Notify-Updater"},
        )
        with urllib.request.urlopen(commit_request, timeout=20) as response:
            commit_info = json.loads(response.read(1048576))
        installer_commit = str(commit_info.get("sha", ""))
        if not re.fullmatch(r"[0-9a-f]{40}", installer_commit):
            raise ValueError("release commit is unavailable")
    except Exception:
        print(json.dumps({"ok": False, "checked_at": now, "update_available": False, "latest_version": norm(tag), "message": "Release commit verification failed; no installer will run."}, separators=(",", ":")))
        raise SystemExit(0)
print(json.dumps({
    "ok": True,
    "checked_at": now,
    "update_available": available,
    "latest_version": norm(tag),
    "tag_name": tag,
    "tgz_url": tgz_url,
    "sha256": sha256,
    "installer_commit": installer_commit,
    "installer_url": f"https://raw.githubusercontent.com/{repo}/{installer_commit}/tools/install_release.sh" if installer_commit else "",
    "message": "Update available." if available else "Installed package is current.",
}, separators=(",", ":")))
PY
)"

write_status "$release_json"
update_available="$(printf '%s' "$release_json" | python3 -c 'import json,sys; print("1" if json.load(sys.stdin).get("update_available") else "0")' 2>/dev/null || printf '0')"
release_ok="$(printf '%s' "$release_json" | python3 -c 'import json,sys; print("1" if json.load(sys.stdin).get("ok") else "0")' 2>/dev/null || printf '0')"
if [ "$release_ok" != "1" ]; then
  fail_update "release_check_failed" "The verified release feed could not be checked. Review Notification Logs for details."
fi
if [ "$CHECK_ONLY" = "1" ]; then
  exit 0
fi
if [ "$update_available" != "1" ]; then
  write_manual_progress "complete" "No newer release remains to be installed."
  exit 0
fi
[ "$GITHUB_UPDATES_ENABLED" = "1" ] || [ "$MANUAL_UPDATE" = "1" ] || exit 0

readarray -t release_values < <(printf '%s' "$release_json" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("tgz_url", "")); print(d.get("sha256", "")); print(d.get("installer_url", "")); print(d.get("latest_version", ""))')
tgz_url="${release_values[0]:-}"
sha256="${release_values[1]:-}"
installer_url="${release_values[2]:-}"
latest="${release_values[3]:-}"
if [ -z "$tgz_url" ] || ! [[ "$sha256" =~ ^[0-9a-f]{64}$ ]] || [ -z "$installer_url" ]; then
  log "Automatic update rejected incomplete release metadata"
  fail_update "release_metadata_invalid" "The release metadata was incomplete or invalid."
fi

release_work="$(mktemp -d /tmp/slsmassnotifyserver-update.XXXXXX)" || fail_update "process_failed" "The update workspace could not be created."
tmp_script="$release_work/install_release.sh"
release_manifest="$release_work/release-manifest.json"
release_signature="$release_work/release-manifest.sig"
release_package="$release_work/package.tgz"
write_manual_progress "installing" "Downloading and installing Mass Notify ${latest}."
if ! curl -fsSL --proto '=https' --proto-redir '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 --max-filesize 1048576 -o "$tmp_script" "$installer_url" >> "$LOG_FILE" 2>&1; then
  log "Automatic update could not download the tagged installer for $latest"
  fail_update "download_failed" "The verified installer could not be downloaded."
fi
release_base="${tgz_url%/*}"
if ! curl -fsSL --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 --max-filesize 16384 -o "$release_manifest" "$release_base/release-manifest.json" >> "$LOG_FILE" 2>&1 \
  || ! curl -fsSL --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 --max-filesize 64 -o "$release_signature" "$release_base/release-manifest.sig" >> "$LOG_FILE" 2>&1 \
  || ! curl -fsSL --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 900 --max-filesize 52428800 -o "$release_package" "$tgz_url" >> "$LOG_FILE" 2>&1 \
  || ! /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_release_verify.py \
      --manifest "$release_manifest" --signature "$release_signature" --installer "$tmp_script" --package "$release_package" --version "$latest" >> "$LOG_FILE" 2>&1; then
  log "Update rejected: publisher signature or artifact verification failed for $latest"
  fail_update "release_verification_failed" "Release authenticity could not be verified. No downloaded installer was executed."
fi
if ! /bin/bash -n "$tmp_script" >> "$LOG_FILE" 2>&1; then
  fail_update "update_script_syntax_error" "The verified installer contains a shell syntax error. No installer was executed." 2
fi
chmod 0700 "$tmp_script"
log "Automatic update installing $latest"
# Install the exact authenticated bytes. Do not fetch the mutable release asset
# a second time or substitute the GitHub API's unauthenticated digest.
verified_sha256="$(sha256sum "$release_package" | awk '{print $1}')"
if SLS_MASS_NOTIFY_TGZ="$release_package" SLS_MASS_NOTIFY_TGZ_URL='' SLS_MASS_NOTIFY_SHA256="$verified_sha256" "$tmp_script" >> "$LOG_FILE" 2>&1; then
  :
else
  installer_status=$?
  log "Automatic update install failed for $latest"
  fail_update "install_command_failed" "Installation failed. Review Notification Logs for details." "$installer_status"
fi
log "Automatic update completed for $latest"
write_manual_progress "complete" "Mass Notify ${latest} was installed successfully. Refreshing the page."
