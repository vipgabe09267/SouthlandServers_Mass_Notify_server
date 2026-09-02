#!/usr/bin/env bash
# Southland Servers Mass Notifications Server by the Southland Servers Group
set -uo pipefail

usage() {
  printf '%s\n' 'Usage: sls_mass_notify_weather_poll.sh'
  printf '%s\n' 'Run one scheduled Weather.gov and Lightning polling cycle.'
}

case "$#" in
  0) ;;
  1)
    case "$1" in
      -h|--help)
        usage
        exit 0
        ;;
      *)
        printf 'Unknown argument: %s\n' "$1" >&2
        usage >&2
        exit 2
        ;;
    esac
    ;;
  *)
    printf 'This worker does not accept positional arguments.\n' >&2
    usage >&2
    exit 2
    ;;
esac

CONFIG_FILE="${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}"
RUNTIME_DIR="${RUNTIME_DIR:-/usr/local/bin/sls_mass_notify}"
DATA_DIR="${DATA_DIR:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin}"
STATUS_FILE="${STATUS_FILE:-$DATA_DIR/status.json}"
LOG="${LOG:-/var/log/sls_mass_notify.log}"
NWS_CROSS_ZONE_CLAIM_HELPER="${NWS_CROSS_ZONE_CLAIM_HELPER:-$RUNTIME_DIR/sls_nws_delivery_claims.py}"
NWS_CROSS_ZONE_CLAIM_STATE="${NWS_CROSS_ZONE_CLAIM_STATE:-$DATA_DIR/nws-cross-zone-delivery-claims.json}"
declare -a worker_pids=()
# A last-ranked zone can wait 55 minutes for earlier zones and still needs
# enough time to submit and hold one maximum valid combined audio sequence.
readonly CORE_WORKER_TIMEOUT_SECONDS=5400
worker_status=0

# A long, serialized Weather cycle (for example several maximum-duration
# alerts) must not be shadowed by another minute-cron wrapper. Per-zone locks
# remain defense in depth, while this quiet outer lock keeps one cycle owner.
exec 7>"$DATA_DIR/weather-poll-cycle.lock"
chmod 0640 "$DATA_DIR/weather-poll-cycle.lock" 2>/dev/null || true
chown asterisk:asterisk "$DATA_DIR/weather-poll-cycle.lock" 2>/dev/null || true
if ! /usr/bin/flock -n 7; then
  exit 0
fi

start_nws_worker() {
  local group_id="$1"
  local group_name="$2"
  local zone="$3"
  local recipients="$4"
  local desktop_recipients="$5"
  local email_recipients="$6"
  local quiet_enabled="$7"
  local quiet_start="$8"
  local quiet_end="$9"
  local critical_events="${10}"
  local webhook_keys="${11}"
  local group_rank="${12}"
  local group_count="${13}"
  local dispatch_cycle_id="${14}"
  local safe_id
  safe_id="$(printf '%s' "$group_id" | tr -cd 'A-Za-z0-9_-' | cut -c1-64)"
  [ -n "$safe_id" ] || safe_id="default"
  [ -n "$zone" ] && {
    [ -n "$recipients" ] || [ -n "$desktop_recipients" ] \
      || [ -n "$email_recipients" ] || [ -n "$webhook_keys" ]
  } || return 0
  NWS_ZONE_OVERRIDE="$zone" \
  NWS_ZONE_GROUP_NAME_OVERRIDE="$group_name" \
  NWS_ZONE_GROUP_ID_OVERRIDE="$group_id" \
  NWS_RECIPIENTS_OVERRIDE="$recipients" \
  NWS_DESKTOP_CLIENTS_OVERRIDE="$desktop_recipients" \
  NWS_EMAIL_RECIPIENTS_OVERRIDE="$email_recipients" \
  NWS_QUIET_HOURS_ENABLED_OVERRIDE="$quiet_enabled" \
  NWS_QUIET_HOURS_START_OVERRIDE="$quiet_start" \
  NWS_QUIET_HOURS_END_OVERRIDE="$quiet_end" \
  NWS_QUIET_CRITICAL_EVENTS_OVERRIDE="$critical_events" \
  NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE="$webhook_keys" \
  NWS_CROSS_ZONE_CLAIM_HELPER="$NWS_CROSS_ZONE_CLAIM_HELPER" \
  NWS_CROSS_ZONE_CLAIM_STATE="$NWS_CROSS_ZONE_CLAIM_STATE" \
  NWS_DISPATCH_CYCLE_ID="$dispatch_cycle_id" \
  NWS_DISPATCH_GROUP_RANK="$group_rank" \
  NWS_DISPATCH_GROUP_COUNT="$group_count" \
  NWS_AUDIO_DELIVERY_LOCK="$DATA_DIR/nws-audio-delivery.lock" \
  STATUS_FILE="$STATUS_FILE" \
  LOCK_FILE="$DATA_DIR/nws-poll-${safe_id}.lock" \
  SEEN_ALERTS="$DATA_DIR/seen-alerts-${safe_id}.txt" \
  PROCESSED_ALERTS="$DATA_DIR/processed-alerts-${safe_id}.txt" \
  AUDIO_DELIVERED_ALERTS="$DATA_DIR/audio-delivered-${safe_id}.txt" \
  LOCAL_DISPATCH_STATE="$DATA_DIR/local-dispatch-intents-${safe_id}.json" \
  EVENT_COOLDOWN_FILE="$DATA_DIR/event-cooldowns-${safe_id}.txt" \
  EXTERNAL_DELIVERY_STATE="$DATA_DIR/external-deliveries-${safe_id}.json" \
  LIGHTNING_GATE_FILE="$DATA_DIR/nws-lightning-gate-${safe_id}.json" \
  /usr/bin/timeout --signal=TERM --kill-after=10 "$CORE_WORKER_TIMEOUT_SECONDS" \
    "$RUNTIME_DIR/sls_mass_notify_nws_poll.sh" &
  worker_pids+=("$!")
}

if [ -r "$CONFIG_FILE" ]; then
  group_payload="$(/usr/bin/python3 - "$CONFIG_FILE" <<'PY'
import hashlib
import json
import re
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as handle:
        config = json.load(handle)
except Exception:
    raise SystemExit(2)
if not isinstance(config, dict):
    raise SystemExit(2)
if str(config.get("enabled", "0")) not in {"1", "true", "True"}:
    print("[]")
    raise SystemExit(0)
groups = config.get("nws_zones") if isinstance(config.get("nws_zones"), list) else []
enabled_desktops = {
    str(client.get("username") or "").strip().lower()
    for client in (config.get("desktop_clients") or [])
    if isinstance(client, dict)
    and str(client.get("enabled", "0")).lower() in {"1", "true", "yes", "on"}
}
if not groups:
    groups = [{
        "name": "Primary Weather Zone",
        "zone": config.get("nws_zone", ""),
        "extensions": config.get("alert_recipients", []),
    }]
records = []
record_ids = set()
def destination_id(row, kind, index):
    identifier = re.sub(r"[^A-Za-z0-9_-]", "", str(row.get("id") or ""))[:64]
    if identifier:
        return identifier
    name = re.sub(r"\s+", " ", str(row.get("name") or f"{kind.title()} {index + 1}")).strip()[:80]
    return kind + "_" + hashlib.sha256(f"{kind}|{name}".encode()).hexdigest()[:16]

all_webhook_keys = []
for kind, key in (("discord", "discord_webhooks"), ("generic", "generic_webhooks")):
    for index, row in enumerate((config.get(key) or [])[:10]):
        if not isinstance(row, dict) or str(row.get("enabled", "1")).lower() in {"0", "false", "no", "off", ""}:
            continue
        all_webhook_keys.append(f"{kind}:{destination_id(row, kind, index)}")
if not (config.get("discord_webhooks") or []) and str(config.get("discord_webhook_url") or "").strip():
    all_webhook_keys.append("discord:discord_legacy")
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
    desktop_recipients = []
    for value in group.get("desktop_clients") or []:
        username = re.sub(r"[^a-z0-9_.-]", "", str(value).strip().lower())[:48]
        if username and username in enabled_desktops and username not in desktop_recipients:
            desktop_recipients.append(username)
    email_recipients = []
    for value in group.get("email_recipients") or []:
        email = str(value).strip()
        if (
            len(email) <= 254
            and re.fullmatch(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}", email)
            and email.lower() not in {item.lower() for item in email_recipients}
        ):
            email_recipients.append(email)
        if len(email_recipients) >= 50:
            break
    name = re.sub(r"\s+", " ", str(group.get("name") or zone)).strip()[:64]
    group_id = re.sub(r"[^A-Za-z0-9_-]", "", str(group.get("id") or ""))[:64]
    if not group_id:
        # Keep legacy ID synthesis byte-for-byte aligned with
        # Slsmassnotifyserver::normalizeNwsZoneGroups().  The index must not
        # participate because Dashboard/Xweather selections persist this ID.
        group_id = "nws_" + hashlib.sha256(f"{name.lower()}|{zone}".encode()).hexdigest()[:12]
    if group_id in record_ids:
        raise SystemExit(2)
    record_ids.add(group_id)
    quiet_enabled = str(group.get("quiet_hours_enabled", config.get("quiet_hours_enabled", "0"))).lower() in {"1", "true", "yes", "on"}
    quiet_start = str(group.get("quiet_hours_start", config.get("quiet_hours_start", "21:00")))
    quiet_end = str(group.get("quiet_hours_end", config.get("quiet_hours_end", "06:00")))
    critical_events = group.get("quiet_critical_events", config.get("quiet_critical_events", []))
    if not isinstance(critical_events, list):
        critical_events = []
    critical_events = [re.sub(r"[\x00-\x1f\x7f]+", " ", str(value)).strip()[:160] for value in critical_events]
    critical_events = [value for value in critical_events if value]
    if "discord_webhook_ids" not in group and "generic_webhook_ids" not in group:
        webhook_keys = all_webhook_keys
    else:
        webhook_keys = []
        for kind, key in (("discord", "discord_webhook_ids"), ("generic", "generic_webhook_ids")):
            values = group.get(key) if isinstance(group.get(key), list) else []
            for value in values:
                identifier = re.sub(r"[^A-Za-z0-9_-]", "", str(value))[:64]
                if identifier:
                    webhook_keys.append(f"{kind}:{identifier}")
    if not recipients and not desktop_recipients and not email_recipients and not webhook_keys:
        continue
    records.append([
        group_id,
        name,
        zone,
        ",".join(recipients),
        ",".join(desktop_recipients),
        " ".join(email_recipients),
        "1" if quiet_enabled else "0",
        quiet_start,
        quiet_end,
        "\x1f".join(critical_events),
        ",".join(dict.fromkeys(webhook_keys)),
    ])
print(json.dumps(records, separators=(",", ":"), ensure_ascii=True))
PY
)"
  group_payload_status=$?
  if [ "$group_payload_status" -eq 0 ]; then
    configured_group_ids_json="$(GROUP_PAYLOAD="$group_payload" /usr/bin/python3 - <<'PY'
import json
import os

records = json.loads(os.environ.get("GROUP_PAYLOAD", "[]"))
print(json.dumps([record[0] for record in records], separators=(",", ":")))
PY
)"
    nws_group_count="$(GROUP_PAYLOAD="$group_payload" /usr/bin/python3 - <<'PY'
import json
import os

records = json.loads(os.environ.get("GROUP_PAYLOAD", "[]"))
print(len(records) if isinstance(records, list) else 0)
PY
)"
    case "$nws_group_count" in
      ''|*[!0-9]*) nws_group_count=0 ;;
    esac
    nws_dispatch_cycle_id="nws_$(date +%s)_$$_${RANDOM}"
    nws_coordination_ready=1
    if [ "$nws_group_count" -gt 0 ]; then
      nws_cycle_request="$(/usr/bin/python3 - "$nws_dispatch_cycle_id" "$nws_group_count" <<'PY'
import json
import sys

print(json.dumps({
    "op": "begin_cycle",
    "cycle_id": sys.argv[1],
    "group_count": int(sys.argv[2]),
}, separators=(",", ":")))
PY
)"
      if [ ! -x "$NWS_CROSS_ZONE_CLAIM_HELPER" ]; then
        printf '%s: NWS cross-zone delivery coordinator is unavailable; Weather.gov workers skipped\n' "$(date)" >> "$LOG"
        nws_coordination_ready=0
        worker_status=1
      elif ! printf '%s\n' "$nws_cycle_request" \
        | NWS_CROSS_ZONE_CLAIM_STATE="$NWS_CROSS_ZONE_CLAIM_STATE" \
          "$NWS_CROSS_ZONE_CLAIM_HELPER" > /dev/null 2>> "$LOG"; then
        printf '%s: NWS cross-zone delivery cycle could not be initialized; Weather.gov workers skipped\n' "$(date)" >> "$LOG"
        nws_coordination_ready=0
        worker_status=1
      fi
    fi
    nws_group_rank=0
    if [ "$nws_coordination_ready" -eq 1 ]; then
    status_helper="$RUNTIME_DIR/sls_nws_status.py"
    if [ -r "$status_helper" ]; then
      STATUS_FILE_PATH="$STATUS_FILE" \
      NWS_CONFIGURED_GROUP_IDS_JSON="$configured_group_ids_json" \
        /usr/bin/python3 "$status_helper" reconcile >> "$LOG" 2>&1 || \
        printf '%s: unable to reconcile per-zone Weather.gov status\n' "$(date)" >> "$LOG"
      chmod 0640 "$STATUS_FILE" 2>/dev/null || true
      chown asterisk:asterisk "$STATUS_FILE" 2>/dev/null || true
    fi
    while IFS= read -r -d '' group_id \
      && IFS= read -r -d '' group_name \
      && IFS= read -r -d '' zone \
      && IFS= read -r -d '' recipients \
      && IFS= read -r -d '' desktop_recipients \
      && IFS= read -r -d '' email_recipients; do
      IFS= read -r -d '' quiet_enabled \
      && IFS= read -r -d '' quiet_start \
      && IFS= read -r -d '' quiet_end \
      && IFS= read -r -d '' critical_events \
      && IFS= read -r -d '' webhook_keys || break
      start_nws_worker "$group_id" "$group_name" "$zone" "$recipients" "$desktop_recipients" "$email_recipients" "$quiet_enabled" "$quiet_start" "$quiet_end" "$critical_events" "$webhook_keys" "$nws_group_rank" "$nws_group_count" "$nws_dispatch_cycle_id"
      nws_group_rank=$((nws_group_rank + 1))
    done < <(GROUP_PAYLOAD="$group_payload" /usr/bin/python3 - <<'PY'
import json
import os
import sys

records = json.loads(os.environ.get("GROUP_PAYLOAD", "[]"))
for record in records:
    for value in record:
        sys.stdout.buffer.write(str(value).encode("utf-8") + b"\0")
PY
    )
    fi
  else
    printf '%s: central configuration is invalid; Weather.gov poll skipped\n' "$(date)" >> "$LOG"
  fi
else
  printf '%s: central configuration is unavailable; weather poll skipped\n' "$(date)" >> "$LOG"
fi

if [ -x "$RUNTIME_DIR/sls_mass_notify_xweather_poll.py" ]; then
  (
    exec 8>"$DATA_DIR/sls_mass_notify_xweather_poll.lock"
    chmod 0640 "$DATA_DIR/sls_mass_notify_xweather_poll.lock" 2>/dev/null || true
    if ! /usr/bin/flock -n 8; then
      exit 0
    fi
    /usr/bin/timeout --signal=TERM --kill-after=10 "$CORE_WORKER_TIMEOUT_SECONDS" \
      "$RUNTIME_DIR/sls_mass_notify_xweather_poll.py"
  ) &
  worker_pids+=("$!")
fi

for worker_pid in "${worker_pids[@]}"; do
  wait "$worker_pid" || worker_status=1
done
exit "$worker_status"
