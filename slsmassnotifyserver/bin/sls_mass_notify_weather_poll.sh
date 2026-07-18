#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -uo pipefail

CONFIG_FILE="${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}"
RUNTIME_DIR="${RUNTIME_DIR:-/usr/local/bin/sls_mass_notify}"
DATA_DIR="${DATA_DIR:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin}"
LOG="${LOG:-/var/log/sls_mass_notify.log}"
declare -a worker_pids=()

start_nws_worker() {
  local group_id="$1"
  local group_name="$2"
  local zone="$3"
  local recipients="$4"
  local safe_id
  safe_id="$(printf '%s' "$group_id" | tr -cd 'A-Za-z0-9_-' | cut -c1-64)"
  [ -n "$safe_id" ] || safe_id="default"
  [ -n "$zone" ] && [ -n "$recipients" ] || return 0
  NWS_ZONE_OVERRIDE="$zone" \
  NWS_ZONE_GROUP_NAME_OVERRIDE="$group_name" \
  NWS_ZONE_GROUP_ID_OVERRIDE="$group_id" \
  NWS_RECIPIENTS_OVERRIDE="$recipients" \
  LOCK_FILE="$DATA_DIR/nws-poll-${safe_id}.lock" \
  SEEN_ALERTS="$DATA_DIR/seen-alerts-${safe_id}.txt" \
  PROCESSED_ALERTS="$DATA_DIR/processed-alerts-${safe_id}.txt" \
  AUDIO_DELIVERED_ALERTS="$DATA_DIR/audio-delivered-${safe_id}.txt" \
  EVENT_COOLDOWN_FILE="$DATA_DIR/event-cooldowns-${safe_id}.txt" \
  LIGHTNING_GATE_FILE="$DATA_DIR/nws-lightning-gate-${safe_id}.json" \
  /usr/bin/timeout 50 "$RUNTIME_DIR/sls_mass_notify_nws_poll.sh" &
  worker_pids+=("$!")
}

if [ -r "$CONFIG_FILE" ]; then
  while IFS= read -r -d '' group_id \
    && IFS= read -r -d '' group_name \
    && IFS= read -r -d '' zone \
    && IFS= read -r -d '' recipients; do
    start_nws_worker "$group_id" "$group_name" "$zone" "$recipients"
  done < <(/usr/bin/python3 - "$CONFIG_FILE" <<'PY'
import hashlib
import json
import re
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as handle:
        config = json.load(handle)
except Exception:
    raise SystemExit(0)
if str(config.get("enabled", "0")) not in {"1", "true", "True"}:
    raise SystemExit(0)
groups = config.get("nws_zones") if isinstance(config.get("nws_zones"), list) else []
if not groups:
    groups = [{
        "name": "Primary Weather Zone",
        "zone": config.get("nws_zone", ""),
        "extensions": config.get("alert_recipients", []),
    }]
for index, group in enumerate(groups[:5]):
    if not isinstance(group, dict):
        continue
    zone = str(group.get("zone") or "").strip().upper()
    if not re.fullmatch(r"[A-Z]{2}[CZ][0-9]{3}", zone):
        continue
    recipients = []
    for value in group.get("extensions") or []:
        extension = re.sub(r"[^0-9]", "", str(value))
        if extension and extension not in recipients:
            recipients.append(extension)
    if not recipients:
        continue
    name = re.sub(r"\s+", " ", str(group.get("name") or zone)).strip()[:64]
    group_id = re.sub(r"[^A-Za-z0-9_-]", "", str(group.get("id") or ""))[:64]
    if not group_id:
        group_id = "nws_" + hashlib.sha256(f"{name.lower()}|{zone}|{index}".encode()).hexdigest()[:12]
    for value in (group_id, name, zone, ",".join(recipients)):
        sys.stdout.buffer.write(value.encode("utf-8") + b"\0")
PY
  )
else
  printf '%s: central configuration is unavailable; weather poll skipped\n' "$(date)" >> "$LOG"
fi

if [ -x "$RUNTIME_DIR/sls_mass_notify_xweather_poll.py" ]; then
  /usr/bin/timeout 50 "$RUNTIME_DIR/sls_mass_notify_xweather_poll.py" &
  worker_pids+=("$!")
fi

worker_status=0
for worker_pid in "${worker_pids[@]}"; do
  wait "$worker_pid" || worker_status=1
done
exit "$worker_status"
