#!/bin/bash
# Southland Servers Mass Notifications Server by the Southland Servers Group

# ============================================================
# NWS Weather Alert -> FreePBX direct recipient script
# Configure the NWS section in the central .config file before enabling alerts.
# ============================================================

usage() {
  printf '%s\n' 'Usage: sls_mass_notify_nws_poll.sh'
  printf '%s\n' 'Poll one configured Weather.gov zone. Configuration is supplied through the protected central config and worker environment.'
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

NWS_ZONE=""
NWS_API_BASE_URL="https://api.weather.gov"
SLS_CALLERID_NAME="SLS Mass Notification System"
SLS_CALLERID_NUM="SLS"
SLS_AUDIO_CONTEXT="sls-alert-audio"
NWS_ALERT_RECIPIENTS=()
NWS_DESKTOP_RECIPIENTS=()
NWS_ZONE_EMAIL_RECIPIENTS=()
SLS_TONE_SOUND_PREFIX="SLS_Mass_Notifications_Plugin/tones"
SLS_TTS_SOUND_PREFIX="SLS_Mass_Notifications_Plugin/tts"
SLS_TONES_DIR="${SLS_TONES_DIR:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tones}"
SLS_TTS_DIR="${SLS_TTS_DIR:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts}"
ASTERISK_SOUNDS_DIR="${ASTERISK_SOUNDS_DIR:-/var/lib/asterisk/sounds}"
SLS_OPENING_TONE="opening_NWS_alert"
SLS_CLOSING_TONE=""
PIPER_BIN="/usr/local/bin/sls_mass_notify/piper/venv/bin/piper"
PIPER_VOICE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx"
PIPER_NWS_VOICE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx"
PIPER_NWS_VOLUME="0.25"
PIPER_MAX_SECONDS="30"
SEEN_ALERTS="${SEEN_ALERTS:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/seen_alerts.txt}"
PROCESSED_ALERTS="${PROCESSED_ALERTS:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/processed_alert_keys.txt}"
AUDIO_DELIVERED_ALERTS="${AUDIO_DELIVERED_ALERTS:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/audio_delivered_alert_keys.txt}"
LOCAL_DISPATCH_STATE="${LOCAL_DISPATCH_STATE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/nws-local-dispatch-intents.json}"
LOG="${LOG:-/var/log/sls_mass_notify.log}"
EVENTS_LOG="${EVENTS_LOG:-/var/log/sls_mass_notify_events.jsonl}"
LOG_RETENTION_DAYS="${LOG_RETENTION_DAYS:-90}"
CONFIG_JSON_FILE="${CONFIG_JSON_FILE:-${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}}"
CONFIG_LOADER="${CONFIG_LOADER:-/usr/local/bin/sls_mass_notify/sls_config.py}"
BRANDED_EMAIL_SCRIPT="${BRANDED_EMAIL_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_branded_email.py}"
NOTIFICATION_DESTINATION_SCRIPT="${NOTIFICATION_DESTINATION_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_notification_destinations.py}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json}"
EXTERNAL_DELIVERY_STATE="${EXTERNAL_DELIVERY_STATE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/nws-external-deliveries.json}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
NWS_STATUS_HELPER="${NWS_STATUS_HELPER:-$SCRIPT_DIR/sls_nws_status.py}"
if [ ! -r "$NWS_STATUS_HELPER" ] && [ -r "$SCRIPT_DIR/sls_mass_notify/sls_nws_status.py" ]; then
  NWS_STATUS_HELPER="$SCRIPT_DIR/sls_mass_notify/sls_nws_status.py"
fi
SPOOL="${SPOOL:-/var/spool/asterisk/outgoing}"
SPOOL_TMP="${SPOOL_TMP:-/var/spool/asterisk/tmp}"
VISUAL_SCRIPT="${VISUAL_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_notify.py}"
TEST_PAYLOAD="${TEST_PAYLOAD:-}"
NWS_DELIVERY_PAYLOAD="${NWS_DELIVERY_PAYLOAD:-}"
FORCE_REPLAY="${FORCE_REPLAY:-0}"
NWS_ALERTS_DRY_RUN="${NWS_ALERTS_DRY_RUN:-0}"
API_FAULT_THRESHOLD="${API_FAULT_THRESHOLD:-3}"
LOCK_FILE="${LOCK_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sls_mass_notify_nws_poll.lock}"
LIGHTNING_GATE_FILE="${LIGHTNING_GATE_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/nws-lightning-gate-default.json}"
NWS_AUDIO_DELIVERY_LOCK="${NWS_AUDIO_DELIVERY_LOCK:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/nws-audio-delivery.lock}"
NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE="${NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE:-}"
NWS_CROSS_ZONE_CLAIM_HELPER="${NWS_CROSS_ZONE_CLAIM_HELPER:-/usr/local/bin/sls_mass_notify/sls_nws_delivery_claims.py}"
NWS_CROSS_ZONE_CLAIM_STATE="${NWS_CROSS_ZONE_CLAIM_STATE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/nws-cross-zone-delivery-claims.json}"
NWS_DISPATCH_CYCLE_ID="${NWS_DISPATCH_CYCLE_ID:-}"
NWS_DISPATCH_GROUP_RANK="${NWS_DISPATCH_GROUP_RANK:-0}"
NWS_DISPATCH_GROUP_COUNT="${NWS_DISPATCH_GROUP_COUNT:-0}"
NWS_DISPATCH_TURN_COMPLETED=0
NWS_CLAIMED_PHONE_COUNT=0
NWS_CLAIMED_DESKTOP_COUNT=0
NWS_CLAIMED_EMAIL_COUNT=0
NWS_CLAIMED_DISCORD_COUNT=0
NWS_CLAIMED_GENERIC_COUNT=0
NWS_LAST_PAGE_HOLD_SECONDS=0
NWS_AUDIO_LOCK_FD=""
MAIL_TO=""
LIVE_EMAIL_TO=""
DISCORD_WEBHOOK_URL=""
MAIL_FROM_NAME="SLS Mass Notification System"
MAIL_FROM_ADDR="no-reply@localhost.localdomain"
EMAIL_HTML_ENABLED="1"
SENDMAIL_BIN="/usr/sbin/sendmail"
SOURCE_EXTENSION=""
SOURCE_NAME="SLS Mass Notification System"
DELIVERY_TARGETS=""
ALERT_EMAIL_SUBJECT="Southland Servers Group PBX: EAS alert triggered - {{event}}"
ALERT_EMAIL_BODY="An EAS alert triggered the configured NWS recipients.

Source Name: {{source_name}}
Trigger Source: {{trigger_source}}
Event: {{event}}
Severity: {{severity}}
Message Type: {{message_type}}
Audio: {{audio}}
Alert ID: {{alert_id}}
Zone: {{zone}}
Time: {{time}}"

nws_coordination_enabled() {
  [ -n "$NWS_DISPATCH_CYCLE_ID" ]
}

nws_coordination_call() {
  local request="$1"
  [ -x "$NWS_CROSS_ZONE_CLAIM_HELPER" ] || return 1
  printf '%s\n' "$request" \
    | NWS_CROSS_ZONE_CLAIM_STATE="$NWS_CROSS_ZONE_CLAIM_STATE" \
      "$NWS_CROSS_ZONE_CLAIM_HELPER"
}

complete_nws_dispatch_turn() {
  local request
  if ! nws_coordination_enabled || [ "$NWS_DISPATCH_TURN_COMPLETED" = "1" ]; then
    return 0
  fi
  request="$(NWS_CYCLE_ID="$NWS_DISPATCH_CYCLE_ID" NWS_GROUP_RANK="$NWS_DISPATCH_GROUP_RANK" /usr/bin/python3 - <<'PY'
import json
import os

print(json.dumps({
    "op": "complete_turn",
    "cycle_id": os.environ["NWS_CYCLE_ID"],
    "rank": int(os.environ["NWS_GROUP_RANK"]),
}, separators=(",", ":")))
PY
)" || return 1
  if nws_coordination_call "$request" > /dev/null 2>> "$LOG"; then
    NWS_DISPATCH_TURN_COMPLETED=1
    return 0
  fi
  printf '%s: unable to complete the cross-zone Weather delivery turn\n' "$(date)" >> "$LOG"
  return 1
}

trap 'complete_nws_dispatch_turn >/dev/null 2>&1 || true' EXIT

wait_for_nws_dispatch_turn() {
  local request
  nws_coordination_enabled || return 0
  request="$(NWS_CYCLE_ID="$NWS_DISPATCH_CYCLE_ID" NWS_GROUP_RANK="$NWS_DISPATCH_GROUP_RANK" /usr/bin/python3 - <<'PY'
import json
import os

print(json.dumps({
    "op": "wait_turn",
    "cycle_id": os.environ["NWS_CYCLE_ID"],
    "rank": int(os.environ["NWS_GROUP_RANK"]),
    "timeout_seconds": 3300,
}, separators=(",", ":")))
PY
)" || return 1
  nws_coordination_call "$request" > /dev/null 2>> "$LOG"
}

claim_cross_zone_destinations() {
  local alert_chain="$1"
  local suppress_local="$2"
  local claim_chain="$alert_chain"
  local request response claimed_lines kind value duplicate_count=0 reserved_count=0 webhook_key
  local -a claimed_phones=()
  local -a claimed_desktops=()
  local -a claimed_emails=()
  local -a claimed_webhooks=()
  local -a webhook_keys=()

  NWS_CLAIMED_PHONE_COUNT=0
  NWS_CLAIMED_DESKTOP_COUNT=0
  NWS_CLAIMED_EMAIL_COUNT=0
  NWS_CLAIMED_DISCORD_COUNT=0
  NWS_CLAIMED_GENERIC_COUNT=0

  # Standalone/manual workers retain their configured targets. Scheduled
  # multi-zone workers always receive a cycle ID from the weather wrapper.
  nws_coordination_enabled || return 0
  [ "$NWS_ALERTS_DRY_RUN" != "1" ] || return 0
  if [ "$FORCE_REPLAY" = "1" ]; then
    claim_chain="${alert_chain}|operator-replay:${NWS_DISPATCH_CYCLE_ID}"
  fi
  if [ -n "$NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE" ]; then
    IFS=',' read -r -a webhook_keys <<< "$NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE"
  fi

  request="$({
    if [ "$suppress_local" != "1" ]; then
      for value in "${NWS_ALERT_RECIPIENTS[@]}"; do
        printf 'phone\0%s\0' "$value"
      done
      for value in "${NWS_DESKTOP_RECIPIENTS[@]}"; do
        printf 'desktop\0%s\0' "$value"
      done
    fi
    for value in "${NWS_ZONE_EMAIL_RECIPIENTS[@]}"; do
      printf 'email\0%s\0' "$value"
    done
    for webhook_key in "${webhook_keys[@]}"; do
      case "$webhook_key" in
        discord:*) printf 'discord\0%s\0' "${webhook_key#discord:}" ;;
        generic:*) printf 'generic\0%s\0' "${webhook_key#generic:}" ;;
      esac
    done
  } | NWS_CLAIM_ALERT_CHAIN="$claim_chain" \
      NWS_CLAIM_GROUP_ID="${NWS_ZONE_GROUP_ID_OVERRIDE:-default}" \
      NWS_CLAIM_GROUP_RANK="$NWS_DISPATCH_GROUP_RANK" \
      /usr/bin/python3 -c '
import hashlib
import json
import os
import sys

raw = sys.stdin.buffer.read(262145)
if len(raw) > 262144:
    raise SystemExit(2)
parts = raw.split(b"\0")
if parts and parts[-1] == b"":
    parts.pop()
if len(parts) % 2:
    raise SystemExit(2)
destinations = {kind: [] for kind in ("phone", "desktop", "email", "discord", "generic")}
for offset in range(0, len(parts), 2):
    kind = parts[offset].decode("ascii", "strict")
    value = parts[offset + 1].decode("utf-8", "strict")
    if kind not in destinations:
        raise SystemExit(2)
    destinations[kind].append(value)
print(json.dumps({
    "op": "claim_many",
    "alert_chain": os.environ["NWS_CLAIM_ALERT_CHAIN"],
    "group_id": os.environ["NWS_CLAIM_GROUP_ID"],
    "group_rank": int(os.environ["NWS_CLAIM_GROUP_RANK"]),
    "reservation_id": "group_" + hashlib.sha256(
        os.environ["NWS_CLAIM_GROUP_ID"].encode("utf-8")
    ).hexdigest(),
    "destinations": destinations,
}, separators=(",", ":")))
')" || return 1

  response="$(nws_coordination_call "$request" 2>> "$LOG")" || return 1
  claimed_lines="$(printf '%s\n' "$response" | /usr/bin/python3 -c '
import json
import sys

result = json.load(sys.stdin)
kinds = ("phone", "desktop", "email", "discord", "generic")
if result.get("ok") is not True or not isinstance(result.get("claimed"), dict):
    raise SystemExit(2)
duplicates = result.get("duplicates")
reserved = result.get("reserved")
if not isinstance(duplicates, dict) or not isinstance(reserved, dict):
    raise SystemExit(2)
duplicate_count = 0
reserved_count = 0
for kind in kinds:
    claimed = result["claimed"].get(kind)
    skipped = duplicates.get(kind)
    blocked = reserved.get(kind)
    if not isinstance(claimed, list) or not isinstance(skipped, list) or not isinstance(blocked, list):
        raise SystemExit(2)
    duplicate_count += len(skipped)
    reserved_count += len(blocked)
    for value in claimed:
        value = str(value)
        if "\t" in value or "\n" in value or "\r" in value:
            raise SystemExit(2)
        print(f"{kind}\t{value}")
print(f"meta\t{duplicate_count}")
print(f"reserved\t{reserved_count}")
')" || return 1

  while IFS=$'\t' read -r kind value; do
    case "$kind" in
      phone)
        claimed_phones+=("$value")
        NWS_CLAIMED_PHONE_COUNT=$((NWS_CLAIMED_PHONE_COUNT + 1))
        ;;
      desktop)
        claimed_desktops+=("$value")
        NWS_CLAIMED_DESKTOP_COUNT=$((NWS_CLAIMED_DESKTOP_COUNT + 1))
        ;;
      email)
        claimed_emails+=("$value")
        NWS_CLAIMED_EMAIL_COUNT=$((NWS_CLAIMED_EMAIL_COUNT + 1))
        ;;
      discord)
        claimed_webhooks+=("discord:$value")
        NWS_CLAIMED_DISCORD_COUNT=$((NWS_CLAIMED_DISCORD_COUNT + 1))
        ;;
      generic)
        claimed_webhooks+=("generic:$value")
        NWS_CLAIMED_GENERIC_COUNT=$((NWS_CLAIMED_GENERIC_COUNT + 1))
        ;;
      meta) duplicate_count="$value" ;;
      reserved) reserved_count="$value" ;;
      *) return 1 ;;
    esac
  done <<< "$claimed_lines"

  NWS_ALERT_RECIPIENTS=("${claimed_phones[@]}")
  NWS_DESKTOP_RECIPIENTS=("${claimed_desktops[@]}")
  LIVE_EMAIL_TO="${claimed_emails[*]}"
  if [ "${#claimed_webhooks[@]}" -gt 0 ]; then
    local IFS=,
    NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE="${claimed_webhooks[*]}"
  else
    NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE=""
  fi
  DELIVERY_TARGETS="$(delivery_targets)"
  if [[ "$duplicate_count" =~ ^[0-9]+$ ]] && [ "$duplicate_count" -gt 0 ]; then
    printf '%s: suppressed %s cross-zone duplicate destination claim(s) for %s\n' \
      "$(date)" "$duplicate_count" "$EVENT" >> "$LOG"
  fi
  if [[ "$reserved_count" =~ ^[0-9]+$ ]] && [ "$reserved_count" -gt 0 ]; then
    # An earlier zone has not yet crossed its durable handoff boundary. Release
    # this zone's partial reservations and defer the complete alert so its
    # alert-level local intent cannot strand the blocked destinations.
    finalize_cross_zone_destinations "$alert_chain" release phone desktop email discord generic >/dev/null 2>&1 || true
    printf '%s: deferred %s because %s destination reservation(s) from an earlier Weather zone are still pending\n' \
      "$(date)" "$EVENT" "$reserved_count" >> "$LOG"
    return 10
  fi
  if [[ "$duplicate_count" =~ ^[0-9]+$ ]] \
    && [ "$duplicate_count" -gt 0 ] \
    && [ "$((${#claimed_phones[@]} + ${#claimed_desktops[@]} + ${#claimed_emails[@]} + ${#claimed_webhooks[@]}))" -eq 0 ]; then
    return 11
  fi
  return 0
}

finalize_cross_zone_destinations() {
  local alert_chain="$1"
  local action="$2"
  shift 2
  local claim_chain="$alert_chain"
  local request response kind expected_count=0

  nws_coordination_enabled || return 0
  [ "$NWS_ALERTS_DRY_RUN" != "1" ] || return 0
  if [ "$FORCE_REPLAY" = "1" ]; then
    claim_chain="${alert_chain}|operator-replay:${NWS_DISPATCH_CYCLE_ID}"
  fi
  for kind in "$@"; do
    case "$kind" in
      phone) expected_count=$((expected_count + NWS_CLAIMED_PHONE_COUNT)) ;;
      desktop) expected_count=$((expected_count + NWS_CLAIMED_DESKTOP_COUNT)) ;;
      email) expected_count=$((expected_count + NWS_CLAIMED_EMAIL_COUNT)) ;;
      discord) expected_count=$((expected_count + NWS_CLAIMED_DISCORD_COUNT)) ;;
      generic) expected_count=$((expected_count + NWS_CLAIMED_GENERIC_COUNT)) ;;
      *) return 1 ;;
    esac
  done
  request="$(NWS_CLAIM_ALERT_CHAIN="$claim_chain" \
    NWS_CLAIM_GROUP_ID="${NWS_ZONE_GROUP_ID_OVERRIDE:-default}" \
    NWS_CLAIM_ACTION="$action" \
    NWS_CLAIM_EXPECTED_COUNT="$expected_count" \
    /usr/bin/python3 - "$@" <<'PY'
import hashlib
import json
import os
import sys

group_id = os.environ["NWS_CLAIM_GROUP_ID"]
print(json.dumps({
    "op": "finalize",
    "alert_chain": os.environ["NWS_CLAIM_ALERT_CHAIN"],
    "reservation_id": "group_" + hashlib.sha256(group_id.encode("utf-8")).hexdigest(),
    "action": os.environ["NWS_CLAIM_ACTION"],
    "expected_count": int(os.environ["NWS_CLAIM_EXPECTED_COUNT"]),
    "kinds": sys.argv[1:],
}, separators=(",", ":")))
PY
)" || return 1
  response="$(nws_coordination_call "$request" 2>> "$LOG")" || return 1
  FINALIZE_RESPONSE="$response" EXPECTED_COUNT="$expected_count" /usr/bin/python3 - <<'PY' || return 1
import json
import os

result = json.loads(os.environ["FINALIZE_RESPONSE"])
if (
    result.get("ok") is not True
    or isinstance(result.get("changed"), bool)
    or result.get("changed") != int(os.environ["EXPECTED_COUNT"])
):
    raise SystemExit(2)
PY
  for kind in "$@"; do
    case "$kind" in
      phone) NWS_CLAIMED_PHONE_COUNT=0 ;;
      desktop) NWS_CLAIMED_DESKTOP_COUNT=0 ;;
      email) NWS_CLAIMED_EMAIL_COUNT=0 ;;
      discord) NWS_CLAIMED_DISCORD_COUNT=0 ;;
      generic) NWS_CLAIMED_GENERIC_COUNT=0 ;;
    esac
  done
}

run_status_mutation() {
  local mutation_json="$1"
  local group_id="${NWS_ZONE_GROUP_ID_OVERRIDE:-default}"
  local group_name="${NWS_ZONE_GROUP_NAME_OVERRIDE:-${NWS_ZONE:-Primary Weather Zone}}"
  local zone="${NWS_ZONE_OVERRIDE:-${NWS_ZONE:-}}"

  if [ ! -r "$NWS_STATUS_HELPER" ]; then
    printf '%s: NWS status helper is unavailable: %s\n' "$(date)" "$NWS_STATUS_HELPER" >> "$LOG"
    return 1
  fi
  STATUS_FILE_PATH="$STATUS_FILE" \
  NWS_STATUS_GROUP_ID="$group_id" \
  NWS_STATUS_GROUP_NAME="$group_name" \
  NWS_STATUS_ZONE="$zone" \
  NWS_STATUS_MUTATION_JSON="$mutation_json" \
    /usr/bin/python3 "$NWS_STATUS_HELPER" mutate
  local result=$?
  if [ "$result" -eq 0 ]; then
    chmod 0640 "$STATUS_FILE" 2>/dev/null || true
    chown asterisk:asterisk "$STATUS_FILE" 2>/dev/null || true
  fi
  return "$result"
}

update_status() {
  local patch_json="$1"
  local mutation_json

  mutation_json="$(STATUS_PATCH_JSON="$patch_json" /usr/bin/python3 - <<'PY'
import json
import os

patch = json.loads(os.environ["STATUS_PATCH_JSON"])
if not isinstance(patch, dict):
    raise SystemExit(2)
print(json.dumps({"patch": patch}, separators=(",", ":")))
PY
)" || return 1
  run_status_mutation "$mutation_json"
}

exec 9>"$LOCK_FILE"
chmod 0640 "$LOCK_FILE" 2>/dev/null || true
chown asterisk:asterisk "$LOCK_FILE" 2>/dev/null || true
if ! flock -n 9; then
  echo "$(date): Another NWS alert poll is already running; skipping this cycle" >> "$LOG"
  update_status "$(printf '{"last_poll_at":%s,"last_poll_status":"already_running","last_poll_message":"Previous NWS poll is still running; this cycle was skipped."}' \
    "$(python3 -c 'import json; from datetime import datetime,timezone; print(json.dumps(datetime.now(timezone.utc).astimezone().isoformat()))')")" \
    >/dev/null 2>&1 || true
  exit 0
fi

timestamp_now() {
  date --iso-8601=seconds
}

queued_delivery_is_current() {
  [ -n "${SLS_WEATHER_JOB_ID:-}" ] || return 0
  /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_weather_queue.py check "$SLS_WEATHER_JOB_ID"
}

prune_event_log() {
  [ -r "$EVENTS_LOG" ] || return 0
  LOG_PATH="$EVENTS_LOG" RETENTION_DAYS="$LOG_RETENTION_DAYS" python3 - <<'PY'
import fcntl
import json
import os
import time
from datetime import datetime

path = os.environ["LOG_PATH"]
try:
    days = int(os.environ.get("RETENTION_DAYS", "90"))
except ValueError:
    days = 90
days = max(1, min(365, days))
cutoff = time.time() - (days * 86400)
retained = []
changed = False
try:
    with open(path, "r+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        lines = [line.rstrip("\n") for line in handle if line.strip()]
        for line in lines:
            try:
                item = json.loads(line)
            except Exception:
                changed = True
                continue
            value = str(item.get("logged_at") or item.get("created_at") or "")
            try:
                ts = datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()
            except Exception:
                ts = time.time()
            if ts >= cutoff:
                retained.append(json.dumps(item, separators=(",", ":")))
            else:
                changed = True
        if changed:
            handle.seek(0)
            handle.truncate(0)
            if retained:
                handle.write("\n".join(retained) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
except FileNotFoundError:
    raise SystemExit(0)
PY
}

json_string() {
  python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1"
}

report_fault() {
  local stage="$1"
  local message="$2"
  local event="${3:-}"
  local alert_id="${4:-}"
  local now

  now="$(timestamp_now)"
  run_status_mutation "$(printf '{"patch":{"last_poll_at":%s,"last_poll_status":"fault","last_poll_message":%s},"fault":{"at":%s,"stage":%s,"message":%s,"event":%s,"alert_id":%s}}' \
    "$(json_string "$now")" \
    "$(json_string "$message")" \
    "$(json_string "$now")" \
    "$(json_string "$stage")" \
    "$(json_string "$message")" \
    "$(json_string "$event")" \
    "$(json_string "$alert_id")")"

  echo "$(date): NWS fault recorded locally — ${stage}: ${message}" >> "$LOG"
  return 0
}

clear_fault_state() {
  run_status_mutation '{"clear_faults":true,"reset_api":true}'
}

clear_api_fault_state() {
  run_status_mutation '{"clear_fault_stage":"api","reset_api":true}'
}

delivery_targets() {
  local phone_targets desktop_targets IFS=,
  phone_targets="${NWS_ALERT_RECIPIENTS[*]}"
  desktop_targets="${NWS_DESKTOP_RECIPIENTS[*]}"
  if [ -n "$phone_targets" ] && [ -n "$desktop_targets" ]; then
    printf '%s; desktop:%s' "$phone_targets" "$desktop_targets"
  elif [ -n "$desktop_targets" ]; then
    printf 'desktop:%s' "$desktop_targets"
  else
    printf '%s' "$phone_targets"
  fi
}

record_api_failure() {
  local message="$1"
  local now
  local threshold="${API_FAULT_THRESHOLD:-3}"

  now="$(timestamp_now)"
  if ! [[ "$threshold" =~ ^[0-9]+$ ]] || [ "$threshold" -lt 1 ] || [ "$threshold" -gt 100 ]; then
    threshold=3
  fi
  run_status_mutation "$(printf '{"api_failure":{"at":%s,"message":%s,"threshold":%s}}' \
    "$(json_string "$now")" \
    "$(json_string "$message")" \
    "$threshold")"
}

append_event_log() {
  local event="$1"
  local severity="$2"
  local msg_type="$3"
  local sound_file="$4"
  local alert_id="$5"
  local subject="$6"
  local body="$7"
  local status="${8:-triggered}"
  local audio_sequence="${9:-$sound_file}"
  local event_id

  event_id="nws-${alert_id##*/}-$(date +%Y%m%d%H%M%S)-$$"

  EVENT_ID="$event_id" \
  EVENT_NAME="$event" \
  EVENT_SEVERITY="$severity" \
  EVENT_MSG_TYPE="$msg_type" \
  EVENT_AUDIO="$sound_file" \
  EVENT_AUDIO_SEQUENCE="$audio_sequence" \
  EVENT_ALERT_ID="$alert_id" \
  EVENT_SUBJECT="$subject" \
  EVENT_BODY="$body" \
  EVENT_STATUS="$status" \
  NWS_RECIPIENTS="$DELIVERY_TARGETS" \
  SOURCE_EXTENSION="$SOURCE_EXTENSION" \
  SOURCE_NAME="$SOURCE_NAME" \
  NWS_ZONE="$NWS_ZONE" \
  EVENTS_LOG_PATH="$EVENTS_LOG" \
  python3 - <<'PY'
import fcntl
import json
import os
from datetime import datetime, timezone

payload = {
    "event_id": os.environ["EVENT_ID"],
    "logged_at": datetime.now(timezone.utc).astimezone().isoformat(),
    "type": "nws",
    "status": os.environ.get("EVENT_STATUS", "triggered"),
    "system_name": os.environ.get("SOURCE_NAME", ""),
    "source_extension": os.environ.get("SOURCE_EXTENSION", ""),
    "source_name": os.environ.get("SOURCE_NAME", ""),
    "trigger_source": "NWS API",
    "page_group": os.environ.get("NWS_RECIPIENTS", ""),
    "event": os.environ.get("EVENT_NAME", ""),
    "severity": os.environ.get("EVENT_SEVERITY", ""),
    "message_type": os.environ.get("EVENT_MSG_TYPE", ""),
    "audio": os.environ.get("EVENT_AUDIO", ""),
    "audio_sequence": [part for part in os.environ.get("EVENT_AUDIO_SEQUENCE", "").split("&") if part],
    "alert_id": os.environ.get("EVENT_ALERT_ID", ""),
    "zone": os.environ.get("NWS_ZONE", ""),
    "mail_subject": os.environ.get("EVENT_SUBJECT", ""),
    "mail_body": os.environ.get("EVENT_BODY", ""),
}

with open(os.environ["EVENTS_LOG_PATH"], "a", encoding="utf-8") as handle:
    fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
    handle.write(json.dumps(payload, ensure_ascii=True) + "\n")
    handle.flush()
    os.fsync(handle.fileno())
    fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
os.chmod(os.environ["EVENTS_LOG_PATH"], 0o640)
PY
}

render_template() {
  local template="$1"
  shift
  TEMPLATE="$template" python3 - "$@" <<'PY'
import os
import sys

template = os.environ.get("TEMPLATE", "")
for pair in sys.argv[1:]:
    key, value = pair.split("=", 1)
    template = template.replace("{{" + key + "}}", value)
print(template)
PY
}

# NWS events eligible for Mass Notifications audio delivery.
declare -A ALERT_SOUNDS
SUPPORTED_NWS_EVENTS_DEFAULT=(
  "Tornado Warning"
  "Tornado Watch"
  "Tornado Emergency"
  "Severe Thunderstorm Warning"
  "Severe Thunderstorm Watch"
  "Flash Flood Emergency"
  "Flash Flood Warning"
  "Flash Flood Watch"
  "Flood Warning"
  "Flood Watch"
  "Red Flag Warning"
  "Fire Weather Watch"
  "Winter Storm Warning"
  "Winter Storm Watch"
  "Ice Storm Warning"
  "High Wind Warning"
  "High Wind Watch"
  "Heat Advisory"
  "Excessive Heat Warning"
  "Extreme Heat Warning"
  "Extreme Heat Watch"
  "Dust Storm Warning"
  "Hurricane Warning"
  "Hurricane Watch"
  "Tropical Storm Warning"
  "Tropical Storm Watch"
  "Storm Surge Warning"
  "Tsunami Warning"
  "Earthquake Warning"
  "Civil Danger Warning"
  "Hazardous Materials Warning"
  "Nuclear Power Plant Warning"
  "Law Enforcement Warning"
  "Evacuation Warning"
  "Evacuation Immediate"
)
for EVENT_NAME in "${SUPPORTED_NWS_EVENTS_DEFAULT[@]}"; do
  ALERT_SOUNDS["$EVENT_NAME"]="supported"
done

QUIET_HOURS_ENABLED="${QUIET_HOURS_ENABLED:-1}"
QUIET_HOURS_START="${QUIET_HOURS_START:-21:00}"
QUIET_HOURS_END="${QUIET_HOURS_END:-06:00}"
QUIET_HOURS_CRITICAL_EVENTS=(
  "Tornado Warning"
  "Tornado Emergency"
  "Flash Flood Emergency"
  "Flash Flood Warning"
  "Evacuation Warning"
  "Evacuation Immediate"
)

load_central_config() {
  local dump_file
  local key
  local value
  local critical_events=()

  if [ ! -x "$CONFIG_LOADER" ] || [ ! -r "$CONFIG_JSON_FILE" ]; then
    echo "$(date): ERROR - Central config or config loader is unavailable" >> "$LOG"
    return 1
  fi
  dump_file="$(mktemp /tmp/sls_mass_notify_config.XXXXXX)" || return 1
  if ! /usr/bin/python3 "$CONFIG_LOADER" "$CONFIG_JSON_FILE" > "$dump_file" 2>>"$LOG"; then
    rm -f "$dump_file"
    return 1
  fi

  NWS_ALERT_RECIPIENTS=()
	NWS_DESKTOP_RECIPIENTS=()
	NWS_ZONE_EMAIL_RECIPIENTS=()
  while IFS= read -r -d '' key && IFS= read -r -d '' value; do
    case "$key" in
      NWS_ALERT_RECIPIENT) NWS_ALERT_RECIPIENTS+=("$value") ;;
		NWS_DESKTOP_CLIENT) NWS_DESKTOP_RECIPIENTS+=("$value") ;;
		NWS_ZONE_EMAIL_RECIPIENT) NWS_ZONE_EMAIL_RECIPIENTS+=("$value") ;;
      QUIET_HOURS_CRITICAL_EVENT) critical_events+=("$value") ;;
      NWS_ALERTS_ENABLED|PUBLIC_PBX_HOST|NWS_API_BASE_URL|NWS_ZONE|SLS_OPENING_TONE|SLS_CLOSING_TONE|PIPER_BIN|PIPER_NWS_VOICE|PIPER_ANNOUNCEMENT_VOICE|PIPER_NWS_VOLUME|PIPER_ANNOUNCEMENT_VOLUME|PIPER_MAX_SECONDS|LOG_RETENTION_DAYS|MAIL_TO|DISCORD_WEBHOOK_URL|QUIET_HOURS_ENABLED|QUIET_HOURS_START|QUIET_HOURS_END|MAIL_FROM_NAME|MAIL_FROM_ADDR|ALERT_EMAIL_SUBJECT|ALERT_EMAIL_BODY|TEST_EMAIL_SUBJECT|TEST_EMAIL_BODY|EMAIL_HTML_ENABLED|AMI_USERNAME|AMI_PASSWORD|AMI_HOST|AMI_PORT|GITHUB_UPDATES_ENABLED|GITHUB_UPDATES_REPOSITORY|GITHUB_UPDATES_CHANNEL)
        printf -v "$key" '%s' "$value"
        ;;
    esac
  done < "$dump_file"
  rm -f "$dump_file"
  QUIET_HOURS_CRITICAL_EVENTS=("${critical_events[@]}")
  PIPER_VOICE="$PIPER_NWS_VOICE"
  return 0
}

if ! load_central_config; then
  report_fault "config" "Central configuration is invalid or unavailable."
  exit 1
fi
if [ "$NWS_API_BASE_URL" != "https://api.weather.gov" ]; then
  echo "$(date): ERROR - Refusing non-weather.gov NWS API base URL" >> "$LOG"
  report_fault "config" "Weather Alerts API must use https://api.weather.gov."
  exit 1
fi
if [ -n "${NWS_ZONE_OVERRIDE:-}" ]; then
  NWS_ZONE="$NWS_ZONE_OVERRIDE"
fi
if [ "${NWS_RECIPIENTS_OVERRIDE+x}" = "x" ]; then
	NWS_ALERT_RECIPIENTS=()
	if [ -n "$NWS_RECIPIENTS_OVERRIDE" ]; then
		IFS=',' read -r -a NWS_ALERT_RECIPIENTS <<< "$NWS_RECIPIENTS_OVERRIDE"
	fi
fi
if [ "${NWS_DESKTOP_CLIENTS_OVERRIDE+x}" = "x" ]; then
	NWS_DESKTOP_RECIPIENTS=()
	if [ -n "$NWS_DESKTOP_CLIENTS_OVERRIDE" ]; then
		IFS=',' read -r -a NWS_DESKTOP_RECIPIENTS <<< "$NWS_DESKTOP_CLIENTS_OVERRIDE"
	fi
fi
if [ "${NWS_EMAIL_RECIPIENTS_OVERRIDE+x}" = "x" ]; then
	NWS_ZONE_EMAIL_RECIPIENTS=()
	if [ -n "$NWS_EMAIL_RECIPIENTS_OVERRIDE" ]; then
		read -r -a NWS_ZONE_EMAIL_RECIPIENTS <<< "$NWS_EMAIL_RECIPIENTS_OVERRIDE"
	fi
fi
if [ "${#NWS_ZONE_EMAIL_RECIPIENTS[@]}" -gt 0 ]; then
	LIVE_EMAIL_TO="${NWS_ZONE_EMAIL_RECIPIENTS[*]}"
fi
if [ "${NWS_QUIET_HOURS_ENABLED_OVERRIDE+x}" = "x" ]; then
  QUIET_HOURS_ENABLED="$NWS_QUIET_HOURS_ENABLED_OVERRIDE"
  QUIET_HOURS_START="${NWS_QUIET_HOURS_START_OVERRIDE:-21:00}"
  QUIET_HOURS_END="${NWS_QUIET_HOURS_END_OVERRIDE:-06:00}"
  QUIET_HOURS_CRITICAL_EVENTS=()
  if [ -n "${NWS_QUIET_CRITICAL_EVENTS_OVERRIDE:-}" ]; then
    IFS=$'\x1f' read -r -a QUIET_HOURS_CRITICAL_EVENTS <<< "$NWS_QUIET_CRITICAL_EVENTS_OVERRIDE"
  fi
fi
CONFIGURED_NWS_ALERT_RECIPIENTS=("${NWS_ALERT_RECIPIENTS[@]}")
CONFIGURED_NWS_DESKTOP_RECIPIENTS=("${NWS_DESKTOP_RECIPIENTS[@]}")
CONFIGURED_NWS_ZONE_EMAIL_RECIPIENTS=("${NWS_ZONE_EMAIL_RECIPIENTS[@]}")
CONFIGURED_NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE="$NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE"
prune_event_log
DELIVERY_TARGETS="$(delivery_targets)"

if declare -p SUPPORTED_NWS_EVENTS >/dev/null 2>&1; then
  unset ALERT_SOUNDS
  declare -A ALERT_SOUNDS
  for EVENT_NAME in "${SUPPORTED_NWS_EVENTS[@]}"; do
    [ -n "$EVENT_NAME" ] && ALERT_SOUNDS["$EVENT_NAME"]="supported"
  done
fi

time_to_minutes() {
  local value="$1"
  local hour="${value%%:*}"
  local minute="${value##*:}"
  if ! [[ "$hour" =~ ^[0-9]{2}$ && "$minute" =~ ^[0-9]{2}$ ]]; then
    echo 0
    return
  fi
  echo $((10#$hour * 60 + 10#$minute))
}

quiet_hours_active() {
  local now_value="${NWS_ALERTS_NOW:-$(date +%H:%M)}"
  local now_minutes
  local start_minutes
  local end_minutes

  [ "${QUIET_HOURS_ENABLED:-0}" = "1" ] || return 1

  now_minutes="$(time_to_minutes "$now_value")"
  start_minutes="$(time_to_minutes "${QUIET_HOURS_START:-21:00}")"
  end_minutes="$(time_to_minutes "${QUIET_HOURS_END:-06:00}")"

  if [ "$start_minutes" -eq "$end_minutes" ]; then
    return 1
  fi
  if [ "$start_minutes" -lt "$end_minutes" ]; then
    [ "$now_minutes" -ge "$start_minutes" ] && [ "$now_minutes" -lt "$end_minutes" ]
    return
  fi
  [ "$now_minutes" -ge "$start_minutes" ] || [ "$now_minutes" -lt "$end_minutes" ]
}

quiet_hours_allows_event() {
  local event="$1"
  local critical_event

  for critical_event in "${QUIET_HOURS_CRITICAL_EVENTS[@]}"; do
    if [ "$event" = "$critical_event" ]; then
      return 0
    fi
  done
  return 1
}

mark_processed_alert() {
  local alert_key="$1"

  [ -n "$alert_key" ] || return 0
  if ! grep -qFx "$alert_key" "$PROCESSED_ALERTS" 2>/dev/null; then
    printf '%s\n' "$alert_key" >> "$PROCESSED_ALERTS"
  fi
	if ! grep -qFx "$alert_key" "$SEEN_ALERTS" 2>/dev/null; then
    printf '%s\n' "$alert_key" >> "$SEEN_ALERTS"
  fi
}

mark_audio_delivered() {
  local alert_key="$1"
  [ -n "$alert_key" ] || return 0
  grep -qFx "$alert_key" "$AUDIO_DELIVERED_ALERTS" 2>/dev/null || printf '%s\n' "$alert_key" >> "$AUDIO_DELIVERED_ALERTS"
  chmod 0640 "$AUDIO_DELIVERED_ALERTS" 2>/dev/null || true
  chown asterisk:asterisk "$AUDIO_DELIVERED_ALERTS" 2>/dev/null || true
}

clear_audio_delivered() {
  local alert_key="$1"
  local tmp_file
  [ -n "$alert_key" ] || return 0
  tmp_file="${AUDIO_DELIVERED_ALERTS}.tmp.$$"
  grep -vFx "$alert_key" "$AUDIO_DELIVERED_ALERTS" 2>/dev/null > "$tmp_file" || true
  chown asterisk:asterisk "$tmp_file" 2>/dev/null || true
  chmod 0640 "$tmp_file" 2>/dev/null || true
  mv -f "$tmp_file" "$AUDIO_DELIVERED_ALERTS"
}

local_dispatch_intent_recorded() {
  local alert_key="$1"

  NWS_LOCAL_DISPATCH_STATE_PATH="$LOCAL_DISPATCH_STATE" \
  NWS_LOCAL_DISPATCH_KEY="$alert_key" \
    /usr/bin/timeout --signal=TERM --kill-after=1 10 \
      /usr/bin/python3 "$NWS_STATUS_HELPER" local-recorded >/dev/null 2>&1
}

queue_local_dispatch_intent() {
  local alert_key="$1"
  local alert_id="$2"
  local event="$3"
  local phone_requested="$4"
  local visual_requested="$5"
  local result

  NWS_LOCAL_DISPATCH_STATE_PATH="$LOCAL_DISPATCH_STATE" \
  NWS_LOCAL_DISPATCH_KEY="$alert_key" \
  NWS_LOCAL_DISPATCH_ALERT_ID="$alert_id" \
  NWS_LOCAL_DISPATCH_EVENT="$event" \
  NWS_LOCAL_DISPATCH_PHONE="$phone_requested" \
  NWS_LOCAL_DISPATCH_VISUAL="$visual_requested" \
    /usr/bin/timeout --signal=TERM --kill-after=1 10 \
      /usr/bin/python3 "$NWS_STATUS_HELPER" local-intent >> "$LOG" 2>&1
  result=$?
  if [ "$result" -eq 0 ] || [ "$result" -eq 10 ]; then
    chmod 0640 "$LOCAL_DISPATCH_STATE" "${LOCAL_DISPATCH_STATE}.lock" 2>/dev/null || true
    chown asterisk:asterisk "$LOCAL_DISPATCH_STATE" "${LOCAL_DISPATCH_STATE}.lock" 2>/dev/null || true
  fi
  return "$result"
}

cancel_local_dispatch_intent() {
  local alert_key="$1"
  local result

  NWS_LOCAL_DISPATCH_STATE_PATH="$LOCAL_DISPATCH_STATE" \
  NWS_LOCAL_DISPATCH_KEY="$alert_key" \
    /usr/bin/timeout --signal=TERM --kill-after=1 10 \
      /usr/bin/python3 "$NWS_STATUS_HELPER" local-cancel >> "$LOG" 2>&1
  result=$?
  if [ "$result" -eq 0 ]; then
    chmod 0640 "$LOCAL_DISPATCH_STATE" "${LOCAL_DISPATCH_STATE}.lock" 2>/dev/null || true
    chown asterisk:asterisk "$LOCAL_DISPATCH_STATE" "${LOCAL_DISPATCH_STATE}.lock" 2>/dev/null || true
  fi
  return "$result"
}

build_tts_text() {
  local alert_b64="$1"

  ALERT_B64="$alert_b64" \
  PIPER_MAX_SECONDS="${PIPER_MAX_SECONDS:-30}" \
  python3 - <<'PY'
import base64
import json
import os
import re

def clean(value):
    value = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+", " ", str(value or ""))
    return re.sub(r"\s+", " ", value).strip()

def first_sentence(value):
    value = clean(value)
    if not value:
        return ""
    match = re.search(r"(.{20,220}?[.!?])(?:\s|$)", value)
    return clean(match.group(1) if match else value[:180])

def shorten_words(value, limit):
    words = clean(value).split()
    if len(words) <= limit:
        return " ".join(words)
    return " ".join(words[:limit]).rstrip(" ,;:") + "."

try:
    feature = json.loads(base64.b64decode(os.environ["ALERT_B64"]).decode("utf-8"))
except Exception:
    feature = {}

props = feature.get("properties", {}) if isinstance(feature, dict) else {}
event = clean(props.get("event")) or "weather alert"
area = clean(props.get("areaDesc")) or "the configured area"
area = "; ".join([part.strip() for part in area.split(";") if part.strip()][:2]) or "the configured area"
headline = first_sentence(props.get("headline"))
description = first_sentence(props.get("description"))
instruction = first_sentence(props.get("instruction"))

event_lower = event.lower()
if "tornado" in event_lower and ("warning" in event_lower or "emergency" in event_lower):
    action = "Take shelter now in an interior room on the lowest floor."
elif "flash flood" in event_lower or "flood" in event_lower:
    action = "Avoid flooded roads and move to higher ground if needed."
elif "severe thunderstorm" in event_lower:
    action = "Move indoors and stay away from windows."
elif "evacuation" in event_lower:
    action = "Follow evacuation instructions immediately."
elif "heat" in event_lower:
    action = "Limit outdoor activity and check on vulnerable people."
elif "winter" in event_lower or "ice" in event_lower:
    action = "Avoid unnecessary travel and monitor local conditions."
elif "fire" in event_lower or "red flag" in event_lower:
    action = "Avoid outdoor burning and follow local emergency guidance."
else:
    action = instruction or headline or description or "Monitor local weather information for instructions."

max_seconds = max(1, min(600, int(os.environ.get("PIPER_MAX_SECONDS", "30") or "30")))
word_limit = max(18, min(1200, max_seconds * 2))
message = f"Weather alert. {event} for {area}. {action}"
print(shorten_words(message, word_limit))
PY
}

generate_tts_audio() {
  local alert_b64="$1"
  local event="$2"
  local alert_id="$3"
  local event_safe
  local base_name
  local tmp_file
  local output_file
  local trimmed_file
  local text
  local duration
  local generation_timeout

  if [ ! -x "$PIPER_BIN" ]; then
    echo "$(date): ERROR — Piper binary not executable: $PIPER_BIN" >> "$LOG"
    return 1
  fi
  if [ ! -r "${PIPER_NWS_VOICE:-$PIPER_VOICE}" ]; then
    echo "$(date): ERROR — Piper voice model not readable: ${PIPER_NWS_VOICE:-$PIPER_VOICE}" >> "$LOG"
    return 1
  fi

  mkdir -p "$SLS_TTS_DIR"
  chown asterisk:asterisk "$SLS_TTS_DIR" 2>/dev/null || true
  chmod 755 "$SLS_TTS_DIR" 2>/dev/null || true

  text="$(build_tts_text "$alert_b64")"
  if [ -z "$text" ]; then
    echo "$(date): ERROR — Unable to build Piper TTS text for $event" >> "$LOG"
    return 1
  fi

  event_safe="$(printf '%s' "$event" | tr '[:upper:] ' '[:lower:]_' | tr -dc 'a-z0-9_-' | cut -c1-40)"
  [ -n "$event_safe" ] || event_safe="alert"
  base_name="nws_${event_safe}_$(date +%Y%m%d%H%M%S)_$$"
  tmp_file="$(mktemp /tmp/nws_tts_XXXXXX.wav)"
  output_file="${SLS_TTS_DIR}/${base_name}.wav"

  generation_timeout=$((PIPER_MAX_SECONDS * 2 + 30))
  [ "$generation_timeout" -lt 25 ] && generation_timeout=25
  [ "$generation_timeout" -gt 900 ] && generation_timeout=900
  if ! printf '%s\n' "$text" | timeout "$generation_timeout" "$PIPER_BIN" --model "${PIPER_NWS_VOICE:-$PIPER_VOICE}" --volume "1.00" --output-file "$tmp_file" >> "$LOG" 2>&1; then
    rm -f "$tmp_file"
    echo "$(date): ERROR — Piper TTS generation failed for $event" >> "$LOG"
    return 1
  fi

  if command -v sox >/dev/null 2>&1; then
    if ! sox -v "${PIPER_NWS_VOLUME:-0.25}" "$tmp_file" -r 8000 -c 1 -b 16 "$output_file" >> "$LOG" 2>&1; then
      rm -f "$tmp_file" "$output_file"
      echo "$(date): ERROR — Unable to convert Piper TTS WAV for $event" >> "$LOG"
      return 1
    fi
  else
    mv "$tmp_file" "$output_file"
    tmp_file=""
  fi
  rm -f "$tmp_file"

  if command -v soxi >/dev/null 2>&1; then
    duration="$(soxi -D "$output_file" 2>/dev/null || echo 0)"
    if awk "BEGIN { exit !($duration > ${PIPER_MAX_SECONDS:-30}) }"; then
		trimmed_file="${output_file}.trimmed.wav"
      if sox "$output_file" "$trimmed_file" trim 0 "${PIPER_MAX_SECONDS:-30}" >> "$LOG" 2>&1; then
        mv "$trimmed_file" "$output_file"
      else
        rm -f "$trimmed_file"
      fi
    fi
  fi

  chown asterisk:asterisk "$output_file" 2>/dev/null || true
  chmod 644 "$output_file"
  echo "$(date): Generated Piper TTS for $event — ${base_name}.wav — ${text}" >> "$LOG"
  printf '%s\n' "$base_name"
}

build_audio_sequence() {
  local tts_base="$1"
  local parts=()
  local files=()
  local combined_base
  local combined_file
  local silence_file
  local IFS

  if [ -n "$SLS_OPENING_TONE" ] && [ -f "${SLS_TONES_DIR}/${SLS_OPENING_TONE}.wav" ]; then
    parts+=("${SLS_TONE_SOUND_PREFIX}/${SLS_OPENING_TONE}")
    files+=("${SLS_TONES_DIR}/${SLS_OPENING_TONE}.wav")
  fi
  if [ -n "$tts_base" ] && [ -f "${SLS_TTS_DIR}/${tts_base}.wav" ]; then
    parts+=("${SLS_TTS_SOUND_PREFIX}/${tts_base}")
    files+=("${SLS_TTS_DIR}/${tts_base}.wav")
  fi
  if [ -n "$SLS_CLOSING_TONE" ] && [ -f "${SLS_TONES_DIR}/${SLS_CLOSING_TONE}.wav" ]; then
    parts+=("${SLS_TONE_SOUND_PREFIX}/${SLS_CLOSING_TONE}")
    files+=("${SLS_TONES_DIR}/${SLS_CLOSING_TONE}.wav")
  fi

  if command -v sox >/dev/null 2>&1 && [ "${#files[@]}" -gt 0 ]; then
    combined_base="nws_sequence_${tts_base}"
    combined_base="$(printf '%s' "$combined_base" | tr -cd 'A-Za-z0-9_-')"
    combined_file="${SLS_TTS_DIR}/${combined_base}.wav"
    silence_file="${SLS_TTS_DIR}/${combined_base}.silence.$$.$RANDOM.wav"
    if sox -n -r 8000 -c 1 -b 16 "$silence_file" trim 0.0 1.0 >> "$LOG" 2>&1 \
      && sox "$silence_file" "${files[@]}" -r 8000 -c 1 -b 16 "$combined_file" >> "$LOG" 2>&1; then
      rm -f "$silence_file"
      chown asterisk:asterisk "$combined_file" 2>/dev/null || true
      chmod 644 "$combined_file"
      printf '%s' "${SLS_TTS_SOUND_PREFIX}/${combined_base}"
      return 0
    fi
    rm -f "$silence_file"
  fi

  if [ "${#parts[@]}" -eq 1 ]; then
    printf '%s' "${parts[0]}"
  fi
}

prune_tts_cache() {
  if [ -d "$SLS_TTS_DIR" ]; then
    # Shared maintenance owns media retention and honors active paging leases.
    :
  fi
}

trigger_visual_alert() {
  if [ -n "${SLS_WEATHER_JOB_ID:-}" ] && ! queued_delivery_is_current; then return 1; fi
  local alert_b64="$1"
  local event="$2"
  local alert_id="$3"
  local phone_delay_seconds="${4:-0}"
  local visual_script="$VISUAL_SCRIPT"
  local targets
	local desktop_targets
	local -a notify_args=()
	local submission_failed=0

  if [ -z "$alert_b64" ]; then
    echo "$(date): Visual live alert skipped for $event — missing alert payload" >> "$LOG"
    return 1
  fi
  if [ ! -f "$visual_script" ]; then
    echo "$(date): Visual live alert skipped for $event — script missing at $visual_script" >> "$LOG"
    return 1
  fi

  targets="$(get_nws_recipient_targets)"
	desktop_targets="$(get_nws_desktop_recipient_targets)"
  if [ -z "$targets" ] && [ -z "$desktop_targets" ]; then
		echo "$(date): Visual live alert skipped for $event — no NWS phone or desktop recipients configured" >> "$LOG"
    return 1
  fi
	# Desktop publication is a local durable-journal write. Do it immediately
	# after the dispatch intent/audio queue boundary, without making a connected
	# SSE client a prerequisite and without waiting for the handset visual delay.
	if [ -n "$desktop_targets" ]; then
		echo "$(date): Publishing visual live alert to the targeted desktop journal for $event — Alert ID: $alert_id" >> "$LOG"
		notify_args=(--api-only --desktop-targets "$desktop_targets" --no-retry)
		if ! /usr/bin/timeout 45 /usr/bin/python3 "$visual_script" --alert-json-b64 "$alert_b64" "${notify_args[@]}" >> "$LOG" 2>&1; then
      echo "$(date): ERROR — Targeted Desktop journal publication failed for $event" >> "$LOG"
      submission_failed=1
    else
      echo "$(date): Desktop alert published to the durable targeted journal; a sleeping or disconnected client is not treated as an error" >> "$LOG"
    fi
  fi

	# Phone SIP NOTIFY intentionally follows audio queueing/start. Its attempt is
	# independent of the desktop result above, so a journal failure cannot
	# suppress the handset visual submission.
	if [ -n "$targets" ]; then
		if [[ "$phone_delay_seconds" =~ ^[0-9]+$ ]] && [ "$phone_delay_seconds" -gt 0 ]; then
			sleep "$phone_delay_seconds"
		fi
		echo "$(date): Submitting visual live alert to Asterisk for $event — Alert ID: $alert_id" >> "$LOG"
		notify_args=(--targets "$targets" --no-api --no-retry)
		if ! /usr/bin/timeout 45 /usr/bin/python3 "$visual_script" --alert-json-b64 "$alert_b64" "${notify_args[@]}" >> "$LOG" 2>&1; then
      echo "$(date): ERROR — Phone visual alert submission to Asterisk failed for $event" >> "$LOG"
      submission_failed=1
    fi
  fi
  [ "$submission_failed" -eq 0 ]
}

get_nws_recipient_targets() {
  local IFS=,
  printf '%s\n' "${NWS_ALERT_RECIPIENTS[*]}"
}

get_nws_desktop_recipient_targets() {
	local IFS=,
	printf '%s\n' "${NWS_DESKTOP_RECIPIENTS[*]}"
}

audio_page_hold_seconds() {
  local sound_sequence="$1"
  local sound_file
  local duration

  [[ "$sound_sequence" =~ ^[A-Za-z0-9_/-]+$ ]] || return 1
  sound_file="${ASTERISK_SOUNDS_DIR}/${sound_sequence}.wav"
  [ -r "$sound_file" ] || return 1
  duration="$(LC_ALL=C /usr/bin/soxi -D "$sound_file" 2>/dev/null)" || return 1
  # Keep Page's originating Local channel and the global serialization lock
  # through its five-second answer window, the complete WAV, and a bounded
  # teardown margin. A slow/no-auto-answer handset therefore cannot overlap
  # the following alert merely because audio duration began at queue time.
  LC_ALL=C awk -v duration="$duration" 'BEGIN {
    if (duration <= 0 || duration > 1767) exit 1
    rounded = int(duration)
    if (duration > rounded) rounded++
    print rounded + 7
  }'
}

queue_audio_to_recipients() {
  local sound_sequence="$1"
  local event="$2"
  local alert_id="$3"
  local recipient
  local callfile
  local queued=0
  local page_hold_seconds
  local call_wait_seconds

  if [ "${#NWS_ALERT_RECIPIENTS[@]}" -eq 0 ]; then
    echo "$(date): ERROR — No NWS alert recipient extensions configured" >> "$LOG"
    report_fault "delivery" "No NWS alert recipient extensions configured" "$event" "$alert_id"
    return 1
  fi

  if ! page_hold_seconds="$(audio_page_hold_seconds "$sound_sequence")"; then
    echo "$(date): ERROR — Unable to measure the complete alert audio sequence" >> "$LOG"
    report_fault "delivery" "Unable to measure the complete alert audio sequence" "$event" "$alert_id"
    return 1
  fi
  call_wait_seconds=$((page_hold_seconds + 30))
  NWS_LAST_PAGE_HOLD_SECONDS="$page_hold_seconds"
  local reservation_recipients
  reservation_recipients="$(IFS=,; echo "${NWS_ALERT_RECIPIENTS[*]}")"
  if ! /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_audio_queue.py --recipients "$reservation_recipients" --duration "$page_hold_seconds" --sound "$sound_sequence" >> "$LOG" 2>&1; then
    report_fault "delivery" "Shared paging queue could not reserve these recipients; no audio was submitted" "$event" "$alert_id"
    return 1
  fi
  queued_delivery_is_current || return 1

  for recipient in "${NWS_ALERT_RECIPIENTS[@]}"; do
    recipient="$(printf '%s' "$recipient" | tr -dc '0-9')"
    [ -n "$recipient" ] || continue
    callfile=$(mktemp "$SPOOL_TMP/sls_alert_XXXXXX.call")
    cat > "$callfile" << CALL
Channel: Local/${recipient}@${SLS_AUDIO_CONTEXT}
CallerID: "${SLS_CALLERID_NAME}" <${SLS_CALLERID_NUM}>
Setvar: SLS_SOUND=${sound_sequence}
Setvar: SLS_CALLERID_NAME=${SLS_CALLERID_NAME}
Setvar: SLS_CALLERID_NUM=${SLS_CALLERID_NUM}
MaxRetries: 0
RetryTime: 5
WaitTime: ${call_wait_seconds}
Application: Wait
Data: ${page_hold_seconds}
CALL
    chown asterisk:asterisk "$callfile" 2>/dev/null || true
    chmod 0640 "$callfile"
    if ! mv "$callfile" "$SPOOL/"; then
      echo "$(date): ERROR — Unable to move alert call file for $recipient into $SPOOL" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      continue
    fi
    queued=$((queued + 1))
  done

  if [ "$queued" -ne "${#NWS_ALERT_RECIPIENTS[@]}" ]; then
    report_fault "delivery" "Not every configured NWS recipient accepted an audio job; some jobs may already be queued" "$event" "$alert_id"
    return 1
  fi

  echo "$(date): Alert call files queued for $event to $queued recipient(s) — ${sound_sequence}" >> "$LOG"
  return 0
}

acquire_audio_delivery_slot() {
  mkdir -p "$(dirname "$NWS_AUDIO_DELIVERY_LOCK")"
  exec {NWS_AUDIO_LOCK_FD}>"$NWS_AUDIO_DELIVERY_LOCK" || return 1
  chmod 0640 "$NWS_AUDIO_DELIVERY_LOCK" 2>/dev/null || true
  chown asterisk:asterisk "$NWS_AUDIO_DELIVERY_LOCK" 2>/dev/null || true
  if ! /usr/bin/flock -w 3300 "$NWS_AUDIO_LOCK_FD"; then
    exec {NWS_AUDIO_LOCK_FD}>&-
    NWS_AUDIO_LOCK_FD=""
    return 1
  fi
}

release_audio_delivery_slot() {
  if [ -n "$NWS_AUDIO_LOCK_FD" ]; then
    /usr/bin/flock -u "$NWS_AUDIO_LOCK_FD" 2>/dev/null || true
    exec {NWS_AUDIO_LOCK_FD}>&-
    NWS_AUDIO_LOCK_FD=""
  fi
}

if [ "${NWS_ALERTS_ENABLED:-1}" != "1" ]; then
  echo "$(date): NWS alerts are disabled in settings; skipping run" >> "$LOG"
  update_status "$(printf '{"last_poll_at":%s,"last_poll_status":"skipped","last_poll_message":"NWS alerts are disabled in settings."}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")")"
  exit 0
fi

prune_tts_cache

external_destinations_allowed() {
  [ "$NWS_ALERTS_DRY_RUN" != "1" ] && [ -z "$TEST_PAYLOAD" ] && [ "$FORCE_REPLAY" != "1" ]
}

external_command_timeout() {
  local maximum="$1"
  local now remaining
  remaining="$maximum"
  if [[ "${SLS_WORKER_DEADLINE_EPOCH:-}" =~ ^[0-9]+$ ]]; then
    now="$(date +%s)"
    # Leave two seconds for status/event writes before the scheduler's own
    # four-second shutdown margin.
    remaining=$((SLS_WORKER_DEADLINE_EPOCH - now - 2))
    [ "$remaining" -lt "$maximum" ] || remaining="$maximum"
  fi
  [ "$remaining" -ge 2 ] || return 1
  printf '%s\n' "$remaining"
}

retry_pending_external_destinations() {
  # Scheduled deliveries have a separate bounded external retry worker.
  [ -z "${SLS_WEATHER_JOB_ID:-}" ] || return 0
  local command_timeout result
  external_destinations_allowed || return 0
  [ -x "$NOTIFICATION_DESTINATION_SCRIPT" ] || return 75
  command_timeout="$(external_command_timeout 40)" || return 75
  SLS_NOTIFICATION_LIVE="1" \
  SLS_NOTIFICATION_TEST="0" \
  SLS_NOTIFICATION_DRY_RUN="0" \
  SLS_DESTINATION_SOURCE="nws" \
  SLS_EXTERNAL_RETRY_ONLY="1" \
    /usr/bin/timeout --signal=TERM --kill-after=1 "$command_timeout" \
      /usr/bin/python3 "$NOTIFICATION_DESTINATION_SCRIPT" "$CONFIG_JSON_FILE" \
      --retry-state "$EXTERNAL_DELIVERY_STATE" >> "$LOG" 2>&1
  result=$?
  case "$result" in
    0|1) return "$result" ;;
    *) return 75 ;;
  esac
}

queue_external_destinations() {
  if [ -n "${SLS_WEATHER_JOB_ID:-}" ] && ! queued_delivery_is_current; then return 75; fi
  local subject="$1"
  local body="$2"
  local alert_type="$3"
  local event="$4"
  local severity="$5"
  local msg_type="$6"
  local audio="$7"
  local alert_id="$8"
  local correlation_key="$9"
  local zone="${10}"
  local event_time="${11}"
  local trigger_source="${12}"
  local trigger_extension="${13}"
  local trigger_name="${14}"
  local audio_sequence="${15}"
  local command_timeout result

  external_destinations_allowed || return 0
  [ -r "$NOTIFICATION_DESTINATION_SCRIPT" ] || return 75
  command_timeout="$(external_command_timeout 12)" || return 75

  SLS_NOTIFICATION_LIVE="1" \
  SLS_NOTIFICATION_TEST="0" \
  SLS_NOTIFICATION_DRY_RUN="0" \
  SLS_DESTINATION_SOURCE="nws" \
  SLS_DESTINATION_SUBJECT="$subject" \
  SLS_DESTINATION_BODY="$body" \
  SLS_DESTINATION_TYPE="$alert_type" \
  SLS_DESTINATION_EVENT="$event" \
  SLS_DESTINATION_SEVERITY="$severity" \
  SLS_DESTINATION_MESSAGE_TYPE="$msg_type" \
  SLS_DESTINATION_AUDIO="$audio" \
  SLS_DESTINATION_EVENT_ID="$alert_id" \
  SLS_EXTERNAL_CORRELATION_KEY="$correlation_key" \
  SLS_DESTINATION_ZONE="$zone" \
  SLS_DESTINATION_RECIPIENTS="$DELIVERY_TARGETS" \
  SLS_DESTINATION_TIME="$event_time" \
  SLS_DESTINATION_TRIGGER="${trigger_name:-$trigger_source}" \
  SLS_DESTINATION_TRIGGER_EXTENSION="$trigger_extension" \
  SLS_DESTINATION_AUDIO_SEQUENCE="$audio_sequence" \
  SLS_EMAIL_RECIPIENTS="$LIVE_EMAIL_TO" \
  SLS_WEBHOOK_DESTINATION_KEYS="$NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE" \
    /usr/bin/timeout --signal=TERM --kill-after=1 "$command_timeout" \
      /usr/bin/python3 - "$NOTIFICATION_DESTINATION_SCRIPT" "$CONFIG_JSON_FILE" "$EXTERNAL_DELIVERY_STATE" >> "$LOG" 2>&1 <<'PY'
import importlib.util
import json
import os
import sys
from pathlib import Path

sys.dont_write_bytecode = True
script_path, config_path, state_path = map(Path, sys.argv[1:4])
spec = importlib.util.spec_from_file_location("sls_nws_external_queue", script_path)
if spec is None or spec.loader is None:
    raise SystemExit(75)
module = importlib.util.module_from_spec(spec)
try:
    spec.loader.exec_module(module)
    with config_path.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    fields = [
        (name, os.environ.get("SLS_DESTINATION_" + name.upper(), ""))
        for name in ("Type", "Event", "Severity", "Zone", "Recipients", "Audio", "Trigger")
    ]
    details = {
        "zone": os.environ.get("SLS_DESTINATION_ZONE", ""),
        "recipients": os.environ.get("SLS_DESTINATION_RECIPIENTS", ""),
        "audio": os.environ.get("SLS_DESTINATION_AUDIO", ""),
        "audio_sequence": os.environ.get("SLS_DESTINATION_AUDIO_SEQUENCE", ""),
        "message_type": os.environ.get("SLS_DESTINATION_MESSAGE_TYPE", ""),
        "trigger": os.environ.get("SLS_DESTINATION_TRIGGER", ""),
        "trigger_extension": os.environ.get("SLS_DESTINATION_TRIGGER_EXTENSION", ""),
    }
    module.queue_external_delivery(
        state_path,
        config,
        os.environ.get("SLS_EXTERNAL_CORRELATION_KEY", ""),
        os.environ.get("SLS_DESTINATION_SUBJECT", "Southland Servers Mass Notification"),
        os.environ.get("SLS_DESTINATION_BODY", "A notification was issued."),
        os.environ.get("SLS_DESTINATION_EVENT", ""),
        os.environ.get("SLS_DESTINATION_SEVERITY", ""),
        fields,
        os.environ.get("SLS_DESTINATION_TIME", ""),
        os.environ.get("SLS_DESTINATION_SOURCE", "nws"),
        os.environ.get("SLS_DESTINATION_EVENT_ID", ""),
        details,
        os.environ.get("SLS_EMAIL_RECIPIENTS", ""),
        [value for value in os.environ.get("SLS_WEBHOOK_DESTINATION_KEYS", "").split(",") if value],
    )
except Exception as exc:
    print(f"external_queue_failed:{type(exc).__name__}", file=sys.stderr)
    raise SystemExit(75)
PY
  result=$?
  [ "$result" -eq 0 ] || return 75
  return 0
}

touch "$SEEN_ALERTS" "$PROCESSED_ALERTS" "$AUDIO_DELIVERED_ALERTS"
chmod 0640 "$SEEN_ALERTS" "$PROCESSED_ALERTS" "$AUDIO_DELIVERED_ALERTS" 2>/dev/null || true
chown asterisk:asterisk "$SEEN_ALERTS" "$PROCESSED_ALERTS" "$AUDIO_DELIVERED_ALERTS" 2>/dev/null || true

# v0.0.7 recorded unsupported Heat Advisory chains as fully processed. Remove
# those stale records once when upgrading so an active advisory can be
# delivered now that it is a supported event.
DEDUP_MIGRATION_FILE="${PROCESSED_ALERTS}.v008-migrated"
if [ ! -f "$DEDUP_MIGRATION_FILE" ]; then
  for dedup_file in "$SEEN_ALERTS" "$PROCESSED_ALERTS"; do
    migration_tmp="${dedup_file}.migration.$$"
    grep -v '^Heat Advisory|' "$dedup_file" > "$migration_tmp" 2>/dev/null || true
    chown asterisk:asterisk "$migration_tmp" 2>/dev/null || true
    chmod 0640 "$migration_tmp" 2>/dev/null || true
    mv -f "$migration_tmp" "$dedup_file"
  done
  printf '%s\n' "Heat Advisory support migration completed $(timestamp_now)" > "$DEDUP_MIGRATION_FILE"
  chown asterisk:asterisk "$DEDUP_MIGRATION_FILE" 2>/dev/null || true
  chmod 0640 "$DEDUP_MIGRATION_FILE" 2>/dev/null || true
fi

# Keep dedup state bounded without erasing active alert-chain history.
for dedup_file in "$SEEN_ALERTS" "$PROCESSED_ALERTS" "$AUDIO_DELIVERED_ALERTS"; do
  if [ "$(wc -l < "$dedup_file" 2>/dev/null || echo 0)" -gt 5000 ]; then
    dedup_tmp="${dedup_file}.tmp.$$"
    tail -n 5000 "$dedup_file" > "$dedup_tmp"
    chown asterisk:asterisk "$dedup_tmp" 2>/dev/null || true
    chmod 0640 "$dedup_tmp" 2>/dev/null || true
    mv -f "$dedup_tmp" "$dedup_file"
  fi
done

echo "$(date): Checking NWS alerts for zone $NWS_ZONE" >> "$LOG"

# Scheduled delivery consumes the observer's validated snapshot without
# pretending to be a manual test (live external routes remain enabled).
INPUT_PAYLOAD="${TEST_PAYLOAD:-$NWS_DELIVERY_PAYLOAD}"
if [ -n "$INPUT_PAYLOAD" ]; then
	if [ ! -f "$INPUT_PAYLOAD" ]; then
	  echo "$(date): ERROR — Test payload not found: $TEST_PAYLOAD" >> "$LOG"
	  report_fault "payload" "Test payload not found" "" "$TEST_PAYLOAD"
	  exit 1
	fi
	if [ "$(stat -c %s "$INPUT_PAYLOAD" 2>/dev/null || echo 0)" -gt 10485760 ]; then
	  echo "$(date): ERROR - Test payload exceeds the 10 MB safety limit" >> "$LOG"
	  report_fault "payload" "Test payload exceeds the 10 MB safety limit" "" "$TEST_PAYLOAD"
	  exit 1
	fi
	ALERTS=$(cat "$INPUT_PAYLOAD")
  echo "$(date): Using validated delivery/test payload" >> "$LOG"
else
	ALERTS=$(curl -fsS --retry 3 --retry-all-errors --retry-connrefused --connect-timeout 10 --max-time 30 --max-filesize 10485760 \
    -H "Accept: application/geo+json" \
    -H "User-Agent: SouthlandServers-Mass-Notifications-Server/0.1.2-beta (https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server)" \
    "${NWS_API_BASE_URL%/}/alerts/active?zone=${NWS_ZONE}&status=actual" 2>>"$LOG") || ALERTS=""
  if [ -z "$ALERTS" ]; then
    echo "$(date): Initial NWS request failed; retrying over IPv4" >> "$LOG"
	  ALERTS=$(curl -4 -fsS --retry 2 --retry-all-errors --retry-connrefused --connect-timeout 10 --max-time 30 --max-filesize 10485760 \
      -H "Accept: application/geo+json" \
	    -H "User-Agent: SouthlandServers-Mass-Notifications-Server/0.1.2-beta (https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server)" \
      "${NWS_API_BASE_URL%/}/alerts/active?zone=${NWS_ZONE}&status=actual" 2>>"$LOG") || ALERTS=""
  fi
fi

if [ -z "$ALERTS" ]; then
  echo "$(date): No response from NWS API" >> "$LOG"
  record_api_failure "No response from NWS API"
  exit 1
fi

POLL_SUMMARY="$(echo "$ALERTS" | python3 -c "
import json
import sys
from collections import Counter

try:
    data = json.load(sys.stdin)
except Exception:
    print('{}')
    sys.exit(0)

if not isinstance(data, dict) or not isinstance(data.get('features'), list):
    print('{}')
    sys.exit(3)
features = data['features']
events = []
candidate_events = []
for feature in features:
    props = feature.get('properties', {}) if isinstance(feature, dict) else {}
    event = str(props.get('event') or '').strip() or 'Unknown'
    status = str(props.get('status') or '').strip()
    msg_type = str(props.get('messageType') or '').strip()
    events.append(event)
    if status == 'Actual' and msg_type != 'Cancel':
        candidate_events.append(event)
payload = {
    'last_poll_feature_count': len(features),
    'last_poll_candidate_count': len(candidate_events),
    'last_poll_events': dict(Counter(events).most_common(12)),
    'last_poll_candidate_events': dict(Counter(candidate_events).most_common(12)),
}
print(json.dumps(payload, separators=(',', ':')))
")"

PARSED_ALERTS="$(echo "$ALERTS" | python3 -c "
import base64
import sys, json
from datetime import datetime

try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(2)

if not isinstance(data, dict) or not isinstance(data.get('features'), list):
    sys.exit(3)
features = data['features']

if not features:
    sys.exit(0)

parsed_alerts = []
for source_index, feature in enumerate(features):
    if not isinstance(feature, dict):
        continue
    props = feature.get('properties', {})
    if not isinstance(props, dict):
        continue
    alert_id = str(feature.get('id') or props.get('id') or '').strip().split('/')[-1]
    event = str(props.get('event') or '').strip()
    severity = str(props.get('severity') or '').strip()
    status = str(props.get('status') or '').strip()
    msg_type = str(props.get('messageType') or '').strip()
    references = props.get('references') or []
    reference_id = ''

    # Only Update messages inherit a prior chain identity. A first-issued
    # Alert keeps its own ID even if a provider unexpectedly includes an old
    # reference, preventing a new alert from colliding with processed state.
    if msg_type.lower() == 'update':
        candidates = []
        for position, ref in enumerate(references):
            if not isinstance(ref, dict):
                continue
            identifier = str(ref.get('identifier') or ref.get('@id') or '').strip().split('/')[-1]
            if not identifier:
                continue
            sent = str(ref.get('sent') or '').strip()
            try:
                sent_key = datetime.fromisoformat(sent.replace('Z', '+00:00')).timestamp()
            except (TypeError, ValueError):
                sent_key = float('inf')
            candidates.append((sent_key, position, identifier))
        if candidates:
            reference_id = min(candidates)[2]

    if status != 'Actual':
        continue
    if msg_type == 'Cancel':
        continue

    key_source = reference_id or alert_id
    # Alert key excludes msg_type to treat updates as the same alert chain.
    if not alert_id or not event or not key_source:
        continue
    alert_key = f'{event}|{key_source}'
    alert_b64 = base64.b64encode(json.dumps(feature, separators=(',', ':'), ensure_ascii=True).encode('utf-8')).decode('ascii')
    values = [alert_id, event, severity, msg_type, alert_key, alert_b64]
    issued_at = str(props.get('onset') or props.get('effective') or props.get('sent') or '')
    try:
        issued_key = datetime.fromisoformat(issued_at.replace('Z', '+00:00')).timestamp()
    except (TypeError, ValueError, OverflowError):
        issued_key = float('inf')
    event_lower = event.lower()
    type_order = 0 if 'advisory' in event_lower else (1 if 'watch' in event_lower else (2 if 'warning' in event_lower else 1))
    parsed_alerts.append((issued_key, type_order, source_index, values))

for _issued, _type_order, _source_index, values in sorted(parsed_alerts):
    print('\t'.join(value.replace('\t', ' ') for value in values))
")"
PARSE_RC=$?

if [ $PARSE_RC -ne 0 ]; then
  echo "$(date): ERROR - Invalid NWS API response (parser exit $PARSE_RC)" >> "$LOG"
  record_api_failure "Invalid NWS API response (parser exit $PARSE_RC)"
  exit 1
fi

if [ -z "$NWS_DELIVERY_PAYLOAD" ]; then
NOW_STATUS_TS="$(timestamp_now)"
POLL_MESSAGE="$(POLL_SUMMARY="$POLL_SUMMARY" python3 - <<'PY'
import json
import os

try:
    summary = json.loads(os.environ.get("POLL_SUMMARY", "{}"))
except Exception:
    summary = {}
features = int(summary.get("last_poll_feature_count") or 0)
candidates = int(summary.get("last_poll_candidate_count") or 0)
events = summary.get("last_poll_candidate_events") or summary.get("last_poll_events") or {}
event_text = ", ".join(f"{name} ({count})" for name, count in list(events.items())[:5])
message = f"NWS poll completed successfully: {features} active feature(s), {candidates} actionable actual alert(s)."
if event_text:
    message += " Events: " + event_text + "."
print(message)
PY
)"
update_status "$(printf '{"last_poll_at":%s,"last_poll_status":"ok","last_poll_message":%s,"last_poll_ok_at":%s,"last_poll_fail_count":0,"last_poll_fail_started_at":""}' \
  "$(json_string "$NOW_STATUS_TS")" \
  "$(json_string "$POLL_MESSAGE")" \
  "$(json_string "$NOW_STATUS_TS")")"
if [ -n "$POLL_SUMMARY" ] && [ "$POLL_SUMMARY" != "{}" ]; then
  update_status "$POLL_SUMMARY"
fi

# Publish a credential-free, per-zone gate for Xweather's free-tier adaptive
# mode. The lightning worker accepts only fresh files and event names that
# explicitly identify thunderstorm activity.
lightning_gate_group_name="${NWS_ZONE_GROUP_NAME_OVERRIDE:-$NWS_ZONE}"
lightning_gate_group_id="${NWS_ZONE_GROUP_ID_OVERRIDE:-default}"
POLL_SUMMARY="$POLL_SUMMARY" LIGHTNING_GATE_FILE="$LIGHTNING_GATE_FILE" NWS_ZONE="$NWS_ZONE" NWS_ZONE_GROUP_NAME="$lightning_gate_group_name" NWS_ZONE_GROUP_ID="$lightning_gate_group_id" python3 - <<'PY' 2>/dev/null || true
import fcntl
import json
import os
import tempfile
from datetime import datetime, timezone
from pathlib import Path

try:
    summary = json.loads(os.environ.get("POLL_SUMMARY", "{}"))
except Exception:
    summary = {}
events = summary.get("last_poll_candidate_events") if isinstance(summary, dict) else {}
if not isinstance(events, dict):
    events = {}
matching = sorted(
    str(name)[:120] for name, count in events.items()
    if "thunderstorm" in str(name).lower() and int(count or 0) > 0
)
target = Path(os.environ["LIGHTNING_GATE_FILE"])
target.parent.mkdir(parents=True, exist_ok=True)
payload = {
    "updated_at": datetime.now(timezone.utc).timestamp(),
    "zone": str(os.environ.get("NWS_ZONE", ""))[:12],
    "group": str(os.environ.get("NWS_ZONE_GROUP_NAME", ""))[:64],
    "group_id": str(os.environ.get("NWS_ZONE_GROUP_ID", "default"))[:64],
    "active": bool(matching),
    "events": matching,
}
fd, temporary = tempfile.mkstemp(prefix=".nws-lightning-gate.", dir=str(target.parent))
try:
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        json.dump(payload, handle, separators=(",", ":"), ensure_ascii=True)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(temporary, 0o640)
    os.replace(temporary, target)
finally:
    try:
        os.unlink(temporary)
    except FileNotFoundError:
        pass
PY
clear_api_fault_state
fi

if ! wait_for_nws_dispatch_turn; then
  echo "$(date): ERROR - Timed out waiting for an earlier configured Weather zone's delivery turn" >> "$LOG"
  report_fault "delivery_queue" "Timed out waiting for an earlier configured Weather zone's delivery turn" "" ""
  exit 1
fi

# Parse alerts
printf '%s\n' "$PARSED_ALERTS" | while IFS=$'\t' read -r ALERT_ID EVENT SEVERITY MSG_TYPE ALERT_KEY ALERT_B64; do

  [ -z "$ALERT_ID" ] && continue
  if ! queued_delivery_is_current; then
    echo "$(date): Queued Weather alert is no longer current; no new delivery attempted" >> "$LOG"
    continue
  fi
  [ -z "$ALERT_KEY" ] && ALERT_KEY="${EVENT}|${ALERT_ID}"
  NWS_ALERT_RECIPIENTS=("${CONFIGURED_NWS_ALERT_RECIPIENTS[@]}")
  NWS_DESKTOP_RECIPIENTS=("${CONFIGURED_NWS_DESKTOP_RECIPIENTS[@]}")
  NWS_ZONE_EMAIL_RECIPIENTS=("${CONFIGURED_NWS_ZONE_EMAIL_RECIPIENTS[@]}")
  LIVE_EMAIL_TO="${NWS_ZONE_EMAIL_RECIPIENTS[*]}"
  NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE="$CONFIGURED_NWS_WEBHOOK_DESTINATION_KEYS_OVERRIDE"
  DELIVERY_TARGETS="$(delivery_targets)"

  # Skip already processed alert chains. NWS time-only updates can arrive with
  # a new alert ID, but references keep them tied to the original alert.
  if [ "$FORCE_REPLAY" != "1" ] && grep -qFx "$ALERT_KEY" "$PROCESSED_ALERTS"; then
    echo "$(date): Skipping update for already processed alert — Event: $EVENT | Key: $ALERT_KEY" >> "$LOG"
    update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"skipped_duplicate","last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_message":%s,"last_delivery_alert_id":%s}' \
      "$(json_string "$(timestamp_now)")" \
      "$(json_string "$EVENT")" \
      "$(json_string "Skipped ${EVENT}; this alert chain was already processed.")" \
      "$(json_string "$ALERT_ID")")"
    continue
  fi

  QUIET_SUPPRESS_PAGING=0
  if quiet_hours_active && ! quiet_hours_allows_event "$EVENT"; then
    QUIET_SUPPRESS_PAGING=1
    echo "$(date): Quiet hours active — paging suppressed for '$EVENT' (not configured as critical)" >> "$LOG"
  fi

  echo "$(date): New alert — Event: $EVENT | Severity: $SEVERITY | Type: $MSG_TYPE" >> "$LOG"

  AUDIO_LABEL="Piper TTS"
  AUDIO_SEQUENCE=""
  TTS_FILE=""
  CROSS_ZONE_FINALIZE_OK=1

  if [ -n "${ALERT_SOUNDS[$EVENT]+_}" ]; then
    echo "$(date): Matched supported NWS event — using Piper TTS" >> "$LOG"
  else
    echo "$(date): Skipping '$EVENT' — unsupported event for Mass Notifications audio (severity: $SEVERITY)" >> "$LOG"
    update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"skipped_unsupported","last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_message":%s,"last_delivery_alert_id":%s}' \
      "$(json_string "$(timestamp_now)")" \
      "$(json_string "$EVENT")" \
      "$(json_string "Skipped ${EVENT}; event is not enabled in supported NWS event list.")" \
      "$(json_string "$ALERT_ID")")"
    continue
  fi

  claim_cross_zone_destinations "$ALERT_KEY" "$QUIET_SUPPRESS_PAGING"
  CROSS_ZONE_CLAIM_STATUS=$?
  case "$CROSS_ZONE_CLAIM_STATUS" in
    0) ;;
    10)
      # Another configured zone still owns an uncommitted reservation. Do not
      # create an alert-level local intent or processed marker that could
      # strand that destination; a later poll retries after handoff or expiry.
      continue
      ;;
    11)
      update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"skipped_cross_zone_duplicate","last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_message":%s,"last_delivery_alert_id":%s}' \
        "$(json_string "$(timestamp_now)")" \
        "$(json_string "$EVENT")" \
        "$(json_string "Skipped ${EVENT}; every destination was already durably claimed by an earlier configured Weather zone.")" \
        "$(json_string "$ALERT_ID")")"
      [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
      continue
      ;;
    *)
      echo "$(date): ERROR - Cross-zone destination claims could not be persisted for $EVENT; no delivery was attempted" >> "$LOG"
      report_fault "delivery_state" "Cross-zone destination claims could not be persisted; no delivery was attempted" "$EVENT" "$ALERT_ID"
      exit 1
      ;;
  esac

  if [ "$QUIET_SUPPRESS_PAGING" = "1" ]; then
    CURRENT_TIME="$(date)"
    QUIET_AUDIO="Piper TTS suppressed by quiet hours"
    QUIET_NOTE="Quiet Hours: The paging system did not go off because this alert is not configured as critical during quiet hours (${QUIET_HOURS_START}-${QUIET_HOURS_END})."
    MAIL_SUBJECT="$(render_template "$ALERT_EMAIL_SUBJECT" \
      "event=$EVENT" \
      "severity=$SEVERITY" \
      "message_type=$MSG_TYPE" \
      "audio=$QUIET_AUDIO" \
      "page_group=$DELIVERY_TARGETS" \
      "alert_id=$ALERT_ID" \
      "zone=$NWS_ZONE" \
      "time=$CURRENT_TIME" \
      "source_extension=$SOURCE_EXTENSION" \
      "source_name=$SOURCE_NAME" \
      "trigger_source=NWS API" \
      "trigger_extension=" \
      "trigger_name=" \
      "audio_sequence=$QUIET_AUDIO")"
    MAIL_BODY="$(render_template "$ALERT_EMAIL_BODY" \
      "event=$EVENT" \
      "severity=$SEVERITY" \
      "message_type=$MSG_TYPE" \
      "audio=$QUIET_AUDIO" \
      "page_group=$DELIVERY_TARGETS" \
      "alert_id=$ALERT_ID" \
      "zone=$NWS_ZONE" \
      "time=$CURRENT_TIME" \
      "source_extension=$SOURCE_EXTENSION" \
      "source_name=$SOURCE_NAME" \
      "trigger_source=NWS API" \
      "trigger_extension=" \
      "trigger_name=" \
      "audio_sequence=$QUIET_AUDIO")"
    MAIL_BODY="${MAIL_BODY}

${QUIET_NOTE}"

    if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
      QUIET_TS="$(timestamp_now)"
      update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"dry_run","last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s,"last_delivery_alert_id":%s}' \
        "$(json_string "$QUIET_TS")" \
        "$(json_string "$EVENT")" \
        "$(json_string "$QUIET_AUDIO")" \
        "$(json_string "Dry run: paging and external destinations would be suppressed or evaluated under quiet hours for ${EVENT}.")" \
        "$(json_string "$DELIVERY_TARGETS")" \
        "$(json_string "$ALERT_ID")")"
      append_event_log "$EVENT" "$SEVERITY" "$MSG_TYPE" "$QUIET_AUDIO" "$ALERT_ID" "$MAIL_SUBJECT" "$MAIL_BODY" "dry_run_quiet_hours"
	    else
	      QUIET_AUX_OK=1
	      QUIET_STATE_PERSISTED=1
	      queue_external_destinations "$MAIL_SUBJECT" "$MAIL_BODY" "Live NWS Alert (Quiet Hours)" \
	        "$EVENT" "$SEVERITY" "$MSG_TYPE" "$QUIET_AUDIO" "$ALERT_ID" "$ALERT_KEY" \
	        "$NWS_ZONE" "$CURRENT_TIME" "NWS API" "" "" "$QUIET_AUDIO"
	      QUIET_EXTERNAL_STATUS=$?
	      if [ "$QUIET_EXTERNAL_STATUS" -eq 75 ]; then
	        echo "$(date): ERROR - Quiet-hours external delivery could not be persisted for $EVENT" >> "$LOG"
	        report_fault "external_state" "Quiet-hours external delivery could not be persisted" "$EVENT" "$ALERT_ID"
	        QUIET_AUX_OK=0
	        QUIET_STATE_PERSISTED=0
		      fi
      if [ "$QUIET_STATE_PERSISTED" = "1" ]; then
        if ! finalize_cross_zone_destinations "$ALERT_KEY" commit email discord generic; then
          echo "$(date): WARNING - Quiet-hours external work is durable, but its cross-zone reservation could not yet be committed for $EVENT" >> "$LOG"
          CROSS_ZONE_FINALIZE_OK=0
        fi
      else
        finalize_cross_zone_destinations "$ALERT_KEY" release email discord generic >/dev/null 2>&1 || true
      fi
      QUIET_TS="$(timestamp_now)"
      if [ "$QUIET_AUX_OK" = "1" ]; then
	        QUIET_DELIVERY_STATUS="queued"
	        QUIET_DELIVERY_MESSAGE="Paging suppressed by quiet hours; external destination work was durably queued for post-local retry for ${EVENT}."
        QUIET_EVENT_STATUS="suppressed_quiet_hours"
        clear_fault_state
      else
        QUIET_DELIVERY_STATUS="partial_failure"
	        QUIET_DELIVERY_MESSAGE="Paging suppressed by quiet hours, but external destination work could not be made durable for ${EVENT}."
        QUIET_EVENT_STATUS="partial_failure"
      fi
      update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":%s,"last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s,"last_delivery_alert_id":%s}' \
        "$(json_string "$QUIET_TS")" \
        "$(json_string "$QUIET_DELIVERY_STATUS")" \
        "$(json_string "$EVENT")" \
        "$(json_string "$QUIET_AUDIO")" \
        "$(json_string "$QUIET_DELIVERY_MESSAGE")" \
        "$(json_string "$DELIVERY_TARGETS")" \
        "$(json_string "$ALERT_ID")")"
      append_event_log "$EVENT" "$SEVERITY" "$MSG_TYPE" "$QUIET_AUDIO" "$ALERT_ID" "$MAIL_SUBJECT" "$MAIL_BODY" "$QUIET_EVENT_STATUS"
	      if [ "$QUIET_STATE_PERSISTED" = "1" ] && [ "$CROSS_ZONE_FINALIZE_OK" = "1" ]; then
	        [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
	      fi
    fi
    continue
  fi

  LOCAL_PHONE_REQUESTED=0
  LOCAL_VISUAL_REQUESTED=0
  LOCAL_DELIVERY_REQUESTED=0
  LOCAL_RECOVERY=0
  LOCAL_STATE_OK=1
  LOCAL_PREP_OK=1
  LOCAL_INTENT_COMMITTED=0
  LOCAL_INTENT_NEW=0
  LOCAL_SUBMISSION_OK=1
  LOCAL_AUDIO_QUEUE_FAILED=0
  VISUAL_DELIVERY_OK=1
  [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ] && LOCAL_PHONE_REQUESTED=1
  if [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ] || [ "${#NWS_DESKTOP_RECIPIENTS[@]}" -gt 0 ]; then
    LOCAL_VISUAL_REQUESTED=1
  fi
  if [ "$LOCAL_PHONE_REQUESTED" = "1" ] || [ "$LOCAL_VISUAL_REQUESTED" = "1" ]; then
    LOCAL_DELIVERY_REQUESTED=1
  fi

  # FORCE_REPLAY is an explicit operator override. Automatic recovery never
  # bypasses this at-most-once local intent check.
  if [ "$LOCAL_DELIVERY_REQUESTED" = "1" ] && [ "$FORCE_REPLAY" != "1" ]; then
    local_dispatch_intent_recorded "$ALERT_KEY"
    LOCAL_RECORDED_STATUS=$?
    case "$LOCAL_RECORDED_STATUS" in
      0)
        LOCAL_RECOVERY=1
        ;;
      1)
        ;;
      *)
        LOCAL_STATE_OK=0
        ;;
    esac

    # Migrate the prior post-audio marker into the stronger all-local-channel
    # intent contract. Audio may already have been submitted, so visual must
    # not be replayed while the outcome is uncertain.
    if [ "$LOCAL_RECOVERY" = "0" ] && grep -qFx "$ALERT_KEY" "$AUDIO_DELIVERED_ALERTS" 2>/dev/null; then
      queue_local_dispatch_intent "$ALERT_KEY" "$ALERT_ID" "$EVENT" "$LOCAL_PHONE_REQUESTED" "$LOCAL_VISUAL_REQUESTED"
      LOCAL_MIGRATION_STATUS=$?
      if [ "$LOCAL_MIGRATION_STATUS" -eq 0 ] || [ "$LOCAL_MIGRATION_STATUS" -eq 10 ]; then
        LOCAL_RECOVERY=1
      else
        LOCAL_STATE_OK=0
      fi
    fi
  fi

  if [ "$LOCAL_RECOVERY" = "1" ]; then
    AUDIO_LABEL="Local dispatch outcome indeterminate after restart"
    AUDIO_SEQUENCE="indeterminate"
    echo "$(date): Durable local dispatch intent found for $EVENT; phone and visual submission will not be replayed" >> "$LOG"
  elif [ "$LOCAL_STATE_OK" = "0" ]; then
    AUDIO_LABEL="Local dispatch state unavailable"
    AUDIO_SEQUENCE=""
  elif [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ]; then
    TTS_FILE="$(generate_tts_audio "$ALERT_B64" "$EVENT" "$ALERT_ID")"
    if [ -z "$TTS_FILE" ]; then
      LOCAL_PREP_OK=0
      AUDIO_LABEL="Piper TTS preparation failed"
      echo "$(date): ERROR - Piper TTS audio was not generated for $EVENT" >> "$LOG"
      report_fault "audio" "Piper TTS audio was not generated" "$EVENT" "$ALERT_ID"
    else
      AUDIO_SEQUENCE="$(build_audio_sequence "$TTS_FILE")"
      if [ -z "$AUDIO_SEQUENCE" ]; then
        LOCAL_PREP_OK=0
        AUDIO_LABEL="Piper TTS sequence preparation failed"
        echo "$(date): ERROR - Unable to build audio sequence for $EVENT" >> "$LOG"
        report_fault "audio" "Unable to build Piper TTS audio sequence" "$EVENT" "$ALERT_ID"
      fi
    fi
  elif [ "${#NWS_DESKTOP_RECIPIENTS[@]}" -gt 0 ]; then
    AUDIO_LABEL="No phone audio requested"
    AUDIO_SEQUENCE=""
    echo "$(date): No phone recipients configured for $EVENT; preparing targeted desktop submission" >> "$LOG"
  else
    AUDIO_LABEL="No local phone or Desktop delivery requested"
    AUDIO_SEQUENCE=""
    echo "$(date): No local recipients configured for $EVENT; preparing external-only delivery" >> "$LOG"
  fi

  CURRENT_TIME="$(date)"
  MAIL_SUBJECT="$(render_template "$ALERT_EMAIL_SUBJECT" \
    "event=$EVENT" \
    "severity=$SEVERITY" \
    "message_type=$MSG_TYPE" \
    "audio=$AUDIO_LABEL" \
    "page_group=$DELIVERY_TARGETS" \
    "alert_id=$ALERT_ID" \
    "zone=$NWS_ZONE" \
    "time=$CURRENT_TIME" \
    "source_extension=$SOURCE_EXTENSION" \
    "source_name=$SOURCE_NAME" \
    "trigger_source=NWS API" \
    "trigger_extension=" \
    "trigger_name=" \
    "audio_sequence=$AUDIO_SEQUENCE")"
  MAIL_BODY="$(render_template "$ALERT_EMAIL_BODY" \
    "event=$EVENT" \
    "severity=$SEVERITY" \
    "message_type=$MSG_TYPE" \
    "audio=$AUDIO_LABEL" \
    "page_group=$DELIVERY_TARGETS" \
    "alert_id=$ALERT_ID" \
    "zone=$NWS_ZONE" \
    "time=$CURRENT_TIME" \
    "source_extension=$SOURCE_EXTENSION" \
    "source_name=$SOURCE_NAME" \
    "trigger_source=NWS API" \
    "trigger_extension=" \
    "trigger_name=" \
    "audio_sequence=$AUDIO_SEQUENCE")"
  AUX_DELIVERY_OK=1
  EXTERNAL_STATE_PERSISTED=1

  if [ "$NWS_ALERTS_DRY_RUN" != "1" ]; then
    # Stage 1: durable external work must exist before either irreversible
    # local submission. A state error therefore produces zero local calls.
    queue_external_destinations "$MAIL_SUBJECT" "$MAIL_BODY" "Live NWS Alert" \
      "$EVENT" "$SEVERITY" "$MSG_TYPE" "$AUDIO_LABEL" "$ALERT_ID" "$ALERT_KEY" \
      "$NWS_ZONE" "$CURRENT_TIME" "NWS API" "" "" "$AUDIO_SEQUENCE"
    EXTERNAL_STATUS=$?
    if [ "$EXTERNAL_STATUS" -eq 75 ]; then
      EXTERNAL_STATE_PERSISTED=0
      AUX_DELIVERY_OK=0
      echo "$(date): ERROR - Live external delivery could not be persisted for $EVENT; local submission was not attempted" >> "$LOG"
      report_fault "external_state" "Live external delivery could not be persisted; no local submission was attempted" "$EVENT" "$ALERT_ID"
    elif [ "$EXTERNAL_STATUS" -eq 1 ]; then
      AUX_DELIVERY_OK=0
      echo "$(date): One or more live external destinations remain durably pending for $EVENT" >> "$LOG"
      report_fault "external" "One or more live external destinations remain pending" "$EVENT" "$ALERT_ID"
    fi
  fi

  if [ "$EXTERNAL_STATE_PERSISTED" = "1" ]; then
    if ! finalize_cross_zone_destinations "$ALERT_KEY" commit email discord generic; then
      echo "$(date): WARNING - External work is durable, but its cross-zone reservation could not yet be committed for $EVENT" >> "$LOG"
      CROSS_ZONE_FINALIZE_OK=0
    fi
  else
    finalize_cross_zone_destinations "$ALERT_KEY" release email discord generic >/dev/null 2>&1 || true
  fi

  if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
    [ "$LOCAL_PHONE_REQUESTED" = "0" ] || echo "$(date): Dry run - would queue call files for $EVENT using $AUDIO_SEQUENCE to recipients: ${NWS_ALERT_RECIPIENTS[*]}" >> "$LOG"
    [ "$LOCAL_VISUAL_REQUESTED" = "0" ] || echo "$(date): Dry run - would queue visual live alert for $EVENT" >> "$LOG"
    DELIVERY_STATUS="dry_run"
    DELIVERY_MESSAGE="Dry run would submit live NWS alert for ${EVENT}; no local intent or external work was created"
  elif [ "$EXTERNAL_STATE_PERSISTED" = "0" ]; then
    finalize_cross_zone_destinations "$ALERT_KEY" release phone desktop >/dev/null 2>&1 || true
    LOCAL_SUBMISSION_OK=0
    DELIVERY_STATUS="failed"
    DELIVERY_MESSAGE="External retry work could not be made durable for ${EVENT}; zero local phone or visual submissions were attempted"
  elif [ "$LOCAL_RECOVERY" = "1" ]; then
    LOCAL_INTENT_COMMITTED=1
    if ! finalize_cross_zone_destinations "$ALERT_KEY" commit phone desktop; then
      echo "$(date): WARNING - Durable local intent exists, but its cross-zone reservation could not yet be committed for $EVENT" >> "$LOG"
      CROSS_ZONE_FINALIZE_OK=0
    fi
    LOCAL_SUBMISSION_OK=0
    DELIVERY_STATUS="indeterminate"
    DELIVERY_MESSAGE="A durable local dispatch intent survived a restart; phone and visual submission were not replayed, so their outcome is indeterminate while external retries continue"
    report_fault "delivery" "Local dispatch outcome is indeterminate after restart; automatic local replay was suppressed" "$EVENT" "$ALERT_ID"
  elif [ "$LOCAL_STATE_OK" = "0" ]; then
    finalize_cross_zone_destinations "$ALERT_KEY" release phone desktop >/dev/null 2>&1 || true
    LOCAL_SUBMISSION_OK=0
    DELIVERY_STATUS="failed"
    DELIVERY_MESSAGE="The durable local dispatch journal was unavailable for ${EVENT}; zero local phone or visual submissions were attempted while external retry work remained durable"
    report_fault "delivery" "Durable local dispatch journal unavailable; no local submission was attempted" "$EVENT" "$ALERT_ID"
  elif [ "$LOCAL_DELIVERY_REQUESTED" = "0" ]; then
    DELIVERY_STATUS="queued"
    DELIVERY_MESSAGE="No local phone or Desktop channel was requested for ${EVENT}; configured external destination work was durably queued"
  else
    if [ "$FORCE_REPLAY" = "1" ]; then
      # An administrator explicitly requested a local replay. It is neither an
      # automatic recovery path nor an exactly-once delivery guarantee.
      LOCAL_INTENT_COMMITTED=1
      if ! finalize_cross_zone_destinations "$ALERT_KEY" commit phone desktop; then
        echo "$(date): WARNING - Operator replay reservation could not yet be committed for $EVENT" >> "$LOG"
        CROSS_ZONE_FINALIZE_OK=0
      fi
    else
      # Stage 2: persist intent before invoking either local channel.
      queue_local_dispatch_intent "$ALERT_KEY" "$ALERT_ID" "$EVENT" "$LOCAL_PHONE_REQUESTED" "$LOCAL_VISUAL_REQUESTED"
      LOCAL_INTENT_STATUS=$?
      if [ "$LOCAL_INTENT_STATUS" -eq 0 ]; then
        LOCAL_INTENT_COMMITTED=1
        LOCAL_INTENT_NEW=1
      elif [ "$LOCAL_INTENT_STATUS" -eq 10 ]; then
        LOCAL_INTENT_COMMITTED=1
        LOCAL_RECOVERY=1
        if ! finalize_cross_zone_destinations "$ALERT_KEY" commit phone desktop; then
          echo "$(date): WARNING - Concurrent durable local intent exists, but its cross-zone reservation could not yet be committed for $EVENT" >> "$LOG"
          CROSS_ZONE_FINALIZE_OK=0
        fi
        LOCAL_SUBMISSION_OK=0
        DELIVERY_STATUS="indeterminate"
        DELIVERY_MESSAGE="A concurrent durable local dispatch intent was found; phone and visual submission were not replayed, so their outcome is indeterminate while external retries continue"
        report_fault "delivery" "Local dispatch outcome is indeterminate; automatic local replay was suppressed" "$EVENT" "$ALERT_ID"
      else
        finalize_cross_zone_destinations "$ALERT_KEY" release phone desktop >/dev/null 2>&1 || true
        LOCAL_SUBMISSION_OK=0
        DELIVERY_STATUS="failed"
        DELIVERY_MESSAGE="The durable local dispatch intent could not be queued for ${EVENT}; zero local phone or visual submissions were attempted while external retry work remained durable"
        report_fault "delivery" "Durable local dispatch intent could not be queued; no local submission was attempted" "$EVENT" "$ALERT_ID"
      fi
    fi

    if [ "$LOCAL_INTENT_COMMITTED" = "1" ] && [ "$LOCAL_RECOVERY" = "0" ]; then
      if [ "$LOCAL_PHONE_REQUESTED" = "1" ]; then
        if [ "$LOCAL_PREP_OK" = "0" ] || ! acquire_audio_delivery_slot; then
          LOCAL_SUBMISSION_OK=0
          LOCAL_AUDIO_QUEUE_FAILED=1
          DELIVERY_STATUS="partial_failure"
          DELIVERY_MESSAGE="Audio preparation or reservation failed for ${EVENT}; visual channels are still attempted independently"
          report_fault "delivery" "$DELIVERY_MESSAGE" "$EVENT" "$ALERT_ID"
        fi
        echo "$(date): Queueing call files for $EVENT - audio sequence: $AUDIO_SEQUENCE" >> "$LOG"
        if [ "$LOCAL_AUDIO_QUEUE_FAILED" = "0" ] && ! queue_audio_to_recipients "$AUDIO_SEQUENCE" "$EVENT" "$ALERT_ID"; then
          LOCAL_SUBMISSION_OK=0
          LOCAL_AUDIO_QUEUE_FAILED=1
          DELIVERY_STATUS="partial_failure"
          DELIVERY_MESSAGE="One or more audio jobs were not accepted for ${EVENT}; visual channels are still attempted and automatic local replay is suppressed"
        fi
      fi
      # Keep the durable intent even if audio failed: visual submission below
      # is irreversible, and a partial audio queue must never be replayed.
      if [ "$LOCAL_INTENT_COMMITTED" = "1" ]; then
        if ! finalize_cross_zone_destinations "$ALERT_KEY" commit phone desktop; then
          echo "$(date): WARNING - Durable local intent exists, but its cross-zone reservation could not yet be committed for $EVENT" >> "$LOG"
          CROSS_ZONE_FINALIZE_OK=0
        fi
      fi
      if [ "$LOCAL_VISUAL_REQUESTED" = "1" ]; then
        if ! trigger_visual_alert "$ALERT_B64" "$EVENT" "$ALERT_ID" 2; then
          VISUAL_DELIVERY_OK=0
          LOCAL_SUBMISSION_OK=0
          DELIVERY_STATUS="partial_failure"
          DELIVERY_MESSAGE="Phone SIP NOTIFY submission or durable Desktop journal publication returned a failure for ${EVENT}; its outcome may be partial and automatic local replay is suppressed"
          report_fault "visual" "Phone SIP NOTIFY submission or durable Desktop journal publication failed; automatic replay suppressed" "$EVENT" "$ALERT_ID"
        fi
      fi
      if [ "$LOCAL_PHONE_REQUESTED" = "1" ] && [ -n "$NWS_AUDIO_LOCK_FD" ]; then
        if [ "$NWS_LAST_PAGE_HOLD_SECONDS" -gt 2 ]; then
          sleep $((NWS_LAST_PAGE_HOLD_SECONDS - 2))
        fi
        release_audio_delivery_slot
      fi
      if [ "$LOCAL_SUBMISSION_OK" = "1" ]; then
        DELIVERY_STATUS="queued"
        DELIVERY_MESSAGE="Local phone and visual submission commands accepted ${EVENT}; endpoint receipt is not confirmed"
      fi
    fi
  fi

  if [ "$AUX_DELIVERY_OK" = "0" ] && [ "$DELIVERY_STATUS" = "queued" ]; then
    DELIVERY_STATUS="partial_failure"
    DELIVERY_MESSAGE="Local submission commands accepted ${EVENT}, while one or more durable external destinations remain pending"
  fi

  DELIVERY_TS="$(timestamp_now)"
  update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":%s,"last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s,"last_delivery_alert_id":%s}' \
    "$(json_string "$DELIVERY_TS")" \
    "$(json_string "$DELIVERY_STATUS")" \
    "$(json_string "$EVENT")" \
    "$(json_string "$AUDIO_LABEL")" \
    "$(json_string "$DELIVERY_MESSAGE")" \
    "$(json_string "$DELIVERY_TARGETS")" \
    "$(json_string "$ALERT_ID")")"
  append_event_log "$EVENT" "$SEVERITY" "$MSG_TYPE" "$AUDIO_LABEL" "$ALERT_ID" "$MAIL_SUBJECT" "$MAIL_BODY" "$DELIVERY_STATUS" "$AUDIO_SEQUENCE"

  if [ "$NWS_ALERTS_DRY_RUN" != "1" ] \
    && [ "$EXTERNAL_STATE_PERSISTED" = "1" ] \
    && { [ "$LOCAL_INTENT_COMMITTED" = "1" ] || [ "$LOCAL_DELIVERY_REQUESTED" = "0" ]; } \
    && [ "$CROSS_ZONE_FINALIZE_OK" = "1" ]; then
    [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
    clear_audio_delivered "$ALERT_KEY"
  fi
  if [ "$LOCAL_SUBMISSION_OK" = "1" ] \
    && [ "$AUX_DELIVERY_OK" = "1" ] \
    && [ "$EXTERNAL_STATE_PERSISTED" = "1" ]; then
    clear_fault_state
  fi

done
ALERT_LOOP_STATUS=$?
complete_nws_dispatch_turn || ALERT_LOOP_STATUS=1
if [ "$ALERT_LOOP_STATUS" -ne 0 ]; then
  exit "$ALERT_LOOP_STATUS"
fi

# Stage 3 runs only after every actionable alert has crossed its local intent
# and local submission path. External network latency can never hold up an
# urgent phone/Desktop attempt, while pending destinations remain durable.
if external_destinations_allowed; then
  retry_pending_external_destinations
  RETRY_STATUS=$?
  if [ "$RETRY_STATUS" -eq 75 ]; then
    echo "$(date): ERROR - Durable external delivery retry could not run after local handling" >> "$LOG"
    report_fault "external_state" "Durable external delivery retry could not run after local handling" "" ""
  elif [ "$RETRY_STATUS" -eq 1 ]; then
    echo "$(date): One or more durable external deliveries remain pending after post-local retry" >> "$LOG"
    report_fault "external" "One or more external destinations remain durably pending after local handling" "" ""
  fi
fi

echo "$(date): Alert check complete" >> "$LOG"
