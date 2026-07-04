#!/bin/bash
# Southland Servers Mass Notifications Server by the Southland Servers Group

# ============================================================
# NWS Weather Alert -> FreePBX direct recipient script
# Configure NWS_ZONE in the generated central config before enabling NWS alerts.
# ============================================================

NWS_ZONE=""
NWS_API_BASE_URL="https://api.weather.gov"
PAGE_GROUP=""
SLS_CALLERID_NAME="SLS Mass Notification System"
SLS_CALLERID_NUM="SLS"
SLS_AUDIO_CONTEXT="sls-alert-audio"
NWS_ALERT_RECIPIENTS=()
SOUNDS_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds"
SLS_SOUND_PREFIX="SLS_Mass_Notifications_Plugin"
SLS_TONE_SOUND_PREFIX="SLS_Mass_Notifications_Plugin/tones"
SLS_TTS_SOUND_PREFIX="SLS_Mass_Notifications_Plugin/tts"
SLS_TONES_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tones"
SLS_TTS_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts"
SLS_OPENING_TONE="opening_Paging_Tone_Opening"
SLS_CLOSING_TONE="closing_Paging_Tone_Closing"
PIPER_BIN="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/venv/bin/piper"
PIPER_VOICE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx"
PIPER_NWS_VOICE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx"
PIPER_NWS_VOLUME="0.85"
PIPER_MAX_SECONDS="30"
SEEN_ALERTS="${SEEN_ALERTS:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/seen_alerts.txt}"
PROCESSED_ALERTS="${PROCESSED_ALERTS:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/processed_alert_keys.txt}"
EVENT_COOLDOWN_FILE="${EVENT_COOLDOWN_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/event_cooldowns.txt}"
COOLDOWN_HOURS=6
LOG="${LOG:-/var/log/sls_mass_notify.log}"
EVENTS_LOG="${EVENTS_LOG:-/var/log/sls_mass_notify_events.jsonl}"
LOG_RETENTION_DAYS="${LOG_RETENTION_DAYS:-90}"
CONFIG_FILE="${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.conf}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json}"
FAULT_STATE_FILE="${FAULT_STATE_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/fault.state}"
SPOOL="/var/spool/asterisk/outgoing"
TEST_PAYLOAD="${TEST_PAYLOAD:-}"
FORCE_REPLAY="${FORCE_REPLAY:-0}"
NWS_ALERTS_DRY_RUN="${NWS_ALERTS_DRY_RUN:-0}"
API_FAULT_THRESHOLD="${API_FAULT_THRESHOLD:-3}"
LOCK_FILE="${LOCK_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sls_mass_notify_nws_poll.lock}"
MAIL_TO=""
DISCORD_WEBHOOK_URL=""
MAIL_FROM_NAME="SLS Mass Notification System"
MAIL_FROM_ADDR="no-reply@localhost"
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

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  echo "$(date): Another NWS alert poll is already running; skipping this cycle" >> "$LOG"
  STATUS_FILE="$STATUS_FILE" python3 - <<'PY' 2>/dev/null || true
import json
import os
from datetime import datetime, timezone

path = os.environ.get("STATUS_FILE", "")
data = {}
try:
    with open(path, "r", encoding="utf-8") as handle:
        data = json.load(handle)
except Exception:
    data = {}
data.update({
    "last_poll_at": datetime.now(timezone.utc).astimezone().isoformat(),
    "last_poll_status": "already_running",
    "last_poll_message": "Previous NWS poll is still running; this cycle was skipped.",
})
try:
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2)
        handle.write("\n")
except Exception:
    pass
PY
  exit 0
fi

timestamp_now() {
  date --iso-8601=seconds
}

prune_event_log() {
  [ -r "$EVENTS_LOG" ] || return 0
  LOG_PATH="$EVENTS_LOG" RETENTION_DAYS="$LOG_RETENTION_DAYS" python3 - <<'PY'
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
    with open(path, "r", encoding="utf-8") as handle:
        lines = [line.rstrip("\n") for line in handle if line.strip()]
except FileNotFoundError:
    raise SystemExit(0)
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
    with open(path, "w", encoding="utf-8") as handle:
        if retained:
            handle.write("\n".join(retained) + "\n")
PY
}

json_string() {
  python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1"
}

get_status_value() {
  local key="$1"

  STATUS_FILE_PATH="$STATUS_FILE" \
  STATUS_KEY="$key" \
  python3 - <<'PY'
import json
import os

path = os.environ["STATUS_FILE_PATH"]
key = os.environ["STATUS_KEY"]

if not os.path.exists(path):
    print("")
    raise SystemExit(0)

try:
    with open(path, "r", encoding="utf-8") as handle:
        data = json.load(handle)
except Exception:
    print("")
    raise SystemExit(0)

value = data.get(key, "")
if value is None:
    value = ""
print(value)
PY
}

update_status() {
  local patch_json="$1"

  STATUS_FILE_PATH="$STATUS_FILE" \
  STATUS_PATCH_JSON="$patch_json" \
  python3 - <<'PY'
import json
import os

path = os.environ["STATUS_FILE_PATH"]
patch = json.loads(os.environ["STATUS_PATCH_JSON"])

data = {}
if os.path.exists(path):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            loaded = json.load(handle)
            if isinstance(loaded, dict):
                data = loaded
    except Exception:
        data = {}

for key, value in patch.items():
    data[key] = value

with open(path, "w", encoding="utf-8") as handle:
    json.dump(data, handle, indent=2, sort_keys=True)
    handle.write("\n")
PY

  chmod 644 "$STATUS_FILE" 2>/dev/null || true
  chown asterisk:asterisk "$STATUS_FILE" 2>/dev/null || true
}

report_fault() {
  local stage="$1"
  local message="$2"
  local event="${3:-}"
  local alert_id="${4:-}"
  local now
  local fault_key
  local subject
  local body

  now="$(timestamp_now)"
  update_status "$(printf '{"last_poll_status":"fault","last_poll_message":%s,"last_fault_at":%s,"last_fault_stage":%s,"last_fault_message":%s,"last_fault_event":%s,"last_fault_alert_id":%s}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$message")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$now")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$stage")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$message")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$event")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$alert_id")")"

  fault_key="${stage}|${message}|${event}|${alert_id}"
  if [ -r "$FAULT_STATE_FILE" ] && [ "$(cat "$FAULT_STATE_FILE" 2>/dev/null)" = "$fault_key" ]; then
    return 1
  fi

  subject="Southland Servers Group PBX: EAS fault detected - ${stage}"
  body="A fault was detected in the NWS alert system.

Stage: ${stage}
Message: ${message}
Event: ${event}
Alert ID: ${alert_id}
Zone: ${NWS_ZONE}
NWS Recipients: ${DELIVERY_TARGETS}
Time: ${now}"

  if send_notification_email "$subject" "$body"; then
    printf '%s\n' "$fault_key" > "$FAULT_STATE_FILE"
    chmod 644 "$FAULT_STATE_FILE" 2>/dev/null || true
    chown asterisk:asterisk "$FAULT_STATE_FILE" 2>/dev/null || true
    update_status "$(printf '{"fault_email_sent_at":%s}' "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$now")")"
  fi

  return 0
}

clear_fault_state() {
  rm -f "$FAULT_STATE_FILE" 2>/dev/null || true
  update_status '{"last_fault_at":"","last_fault_stage":"","last_fault_message":"","last_fault_event":"","last_fault_alert_id":"","fault_email_sent_at":"","last_poll_fail_count":0,"last_poll_fail_started_at":""}'
}

delivery_targets() {
  local IFS=,
  printf '%s' "${NWS_ALERT_RECIPIENTS[*]}"
}

record_api_failure() {
  local message="$1"
  local now
  local fail_count
  local fail_started_at
  local status_message

  now="$(timestamp_now)"
  fail_count="$(get_status_value last_poll_fail_count)"
  fail_count="${fail_count:-0}"
  if ! [[ "$fail_count" =~ ^[0-9]+$ ]]; then
    fail_count=0
  fi
  fail_count=$((fail_count + 1))

  fail_started_at="$(get_status_value last_poll_fail_started_at)"
  if [ -z "$fail_started_at" ]; then
    fail_started_at="$now"
  fi

  status_message="NWS API poll failure ${fail_count}/${API_FAULT_THRESHOLD}: ${message}"
  update_status "$(printf '{"last_poll_at":%s,"last_poll_status":%s,"last_poll_message":%s,"last_poll_fail_count":%s,"last_poll_fail_started_at":%s}' \
    "$(json_string "$now")" \
    "$(json_string "$([ "$fail_count" -ge "$API_FAULT_THRESHOLD" ] && printf 'fault' || printf 'warning')")" \
    "$(json_string "$status_message")" \
    "$(json_string "$fail_count")" \
    "$(json_string "$fail_started_at")")"

  if [ "$fail_count" -ge "$API_FAULT_THRESHOLD" ]; then
    report_fault "api" "$message"
  fi
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
    handle.write(json.dumps(payload, ensure_ascii=True) + "\n")
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

if [ -r "$CONFIG_FILE" ]; then
  # Generated by the FreePBX Mass Notifications settings page.
  . "$CONFIG_FILE"
fi
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
  if ! grep -qF "$alert_key" "$SEEN_ALERTS" 2>/dev/null; then
    printf '%s\n' "$alert_key" >> "$SEEN_ALERTS"
  fi
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

  if ! printf '%s\n' "$text" | timeout 25 "$PIPER_BIN" --model "${PIPER_NWS_VOICE:-$PIPER_VOICE}" --volume "1.00" --output-file "$tmp_file" >> "$LOG" 2>&1; then
    rm -f "$tmp_file"
    echo "$(date): ERROR — Piper TTS generation failed for $event" >> "$LOG"
    return 1
  fi

  if command -v sox >/dev/null 2>&1; then
    if ! sox -v "${PIPER_NWS_VOLUME:-0.85}" "$tmp_file" -r 8000 -c 1 -b 16 "$output_file" >> "$LOG" 2>&1; then
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
      trimmed_file="${output_file}.trimmed"
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
    find "$SLS_TTS_DIR" -maxdepth 1 -type f -name '*.wav' -mtime +7 -delete 2>/dev/null || true
  fi
}

trigger_visual_alert() {
  local alert_b64="$1"
  local event="$2"
  local alert_id="$3"
  local visual_script="/usr/local/bin/sls_mass_notify/sls_notify.py"
  local targets

  if [ -z "$alert_b64" ]; then
    echo "$(date): Visual live alert skipped for $event — missing alert payload" >> "$LOG"
    return 0
  fi
  if [ ! -f "$visual_script" ]; then
    echo "$(date): Visual live alert skipped for $event — script missing at $visual_script" >> "$LOG"
    return 0
  fi

  targets="$(get_nws_recipient_targets)"
  if [ -z "$targets" ]; then
    echo "$(date): Visual live alert skipped for $event — no NWS recipient extensions configured" >> "$LOG"
    return 0
  fi
  echo "$(date): Queueing visual live alert for $event — Alert ID: $alert_id" >> "$LOG"
  nohup /usr/bin/python3 "$visual_script" --alert-json-b64 "$alert_b64" --targets "$targets" >> "$LOG" 2>&1 &
}

get_nws_recipient_targets() {
  local IFS=,
  printf '%s\n' "${NWS_ALERT_RECIPIENTS[*]}"
}

queue_audio_to_recipients() {
  local sound_sequence="$1"
  local event="$2"
  local alert_id="$3"
  local recipient
  local callfile
  local queued=0

  if [ "${#NWS_ALERT_RECIPIENTS[@]}" -eq 0 ]; then
    echo "$(date): ERROR — No NWS alert recipient extensions configured" >> "$LOG"
    report_fault "delivery" "No NWS alert recipient extensions configured" "$event" "$alert_id"
    return 1
  fi

  for recipient in "${NWS_ALERT_RECIPIENTS[@]}"; do
    recipient="$(printf '%s' "$recipient" | tr -dc '0-9')"
    [ -n "$recipient" ] || continue
    callfile=$(mktemp /tmp/sls_alert_XXXXXX.call)
    cat > "$callfile" << CALL
Channel: Local/${recipient}@${SLS_AUDIO_CONTEXT}
CallerID: "${SLS_CALLERID_NAME}" <${SLS_CALLERID_NUM}>
Setvar: SLS_SOUND=${sound_sequence}
Setvar: SLS_CALLERID_NAME=${SLS_CALLERID_NAME}
Setvar: SLS_CALLERID_NUM=${SLS_CALLERID_NUM}
MaxRetries: 6
RetryTime: 10
WaitTime: 180
Application: Wait
Data: 1
CALL
    chown asterisk:asterisk "$callfile" 2>/dev/null || true
    chmod 777 "$callfile"
    if ! mv "$callfile" "$SPOOL/"; then
      echo "$(date): ERROR — Unable to move alert call file for $recipient into $SPOOL" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      continue
    fi
    queued=$((queued + 1))
  done

  if [ "$queued" -eq 0 ]; then
    report_fault "delivery" "Unable to queue alert calls to configured NWS recipients" "$event" "$alert_id"
    return 1
  fi

  echo "$(date): Alert call files queued for $event to $queued recipient(s) — ${sound_sequence}" >> "$LOG"
  return 0
}

get_event_cooldown_ts() {
  local event_key="$1"

  awk -F: -v event_key="$event_key" '$1 == event_key { ts = $2 } END { print ts }' "$EVENT_COOLDOWN_FILE" 2>/dev/null
}

set_event_cooldown_ts() {
  local event_key="$1"
  local timestamp="$2"
  local tmp_file

  tmp_file="$(mktemp /var/tmp/sls_event_cooldowns.XXXXXX)"
  awk -F: -v event_key="$event_key" '$1 != event_key { print }' "$EVENT_COOLDOWN_FILE" 2>/dev/null > "$tmp_file" || true
  printf '%s:%s\n' "$event_key" "$timestamp" >> "$tmp_file"
  mv "$tmp_file" "$EVENT_COOLDOWN_FILE"
  chmod 644 "$EVENT_COOLDOWN_FILE" 2>/dev/null || true
}

if [ "${NWS_ALERTS_ENABLED:-1}" != "1" ]; then
  echo "$(date): NWS alerts are disabled in settings; skipping run" >> "$LOG"
  update_status "$(printf '{"last_poll_at":%s,"last_poll_status":"skipped","last_poll_message":"NWS alerts are disabled in settings."}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")")"
  exit 0
fi

prune_tts_cache

send_notification_email() {
  local subject="$1"
  local body="$2"

  if [ -z "$(printf '%s' "$MAIL_TO" | tr -d '[:space:]')" ]; then
    echo "$(date): Notification email skipped — no recipients configured" >> "$LOG"
    return 0
  fi

  if [ ! -x "$SENDMAIL_BIN" ]; then
    echo "$(date): ERROR — sendmail not found at $SENDMAIL_BIN" >> "$LOG"
    return 1
  fi

  {
    printf 'From: "%s" <%s>\n' "$MAIL_FROM_NAME" "$MAIL_FROM_ADDR"
    printf 'To: "Undisclosed Recipients" <%s>\n' "$MAIL_FROM_ADDR"
    printf 'Bcc: %s\n' "$(printf '%s' "$MAIL_TO" | sed 's/ /, /g')"
    printf 'Subject: %s\n' "$subject"
    printf 'Content-Type: text/plain; charset=UTF-8\n'
    printf '\n'
    printf '%s\n' "$body"
  } | "$SENDMAIL_BIN" -t -f "$MAIL_FROM_ADDR"
}

send_discord_alert() {
  local subject="$1"
  local body="$2"
  local alert_type="$3"
  local event="$4"
  local severity="$5"
  local msg_type="$6"
  local audio="$7"
  local alert_id="$8"
  local zone="$9"
  local event_time="${10}"
  local trigger_source="${11}"
  local trigger_extension="${12}"
  local trigger_name="${13}"
  local audio_sequence="${14}"

  if [ -z "$(printf '%s' "$DISCORD_WEBHOOK_URL" | tr -d '[:space:]')" ]; then
    echo "$(date): Discord notification skipped — no webhook configured" >> "$LOG"
    return 0
  fi

  DISCORD_WEBHOOK_URL="$DISCORD_WEBHOOK_URL" \
  DISCORD_SUBJECT="$subject" \
  DISCORD_BODY="$body" \
  DISCORD_TYPE="$alert_type" \
  DISCORD_EVENT="$event" \
  DISCORD_SEVERITY="$severity" \
  DISCORD_MESSAGE_TYPE="$msg_type" \
  DISCORD_AUDIO="$audio" \
  DISCORD_ALERT_ID="$alert_id" \
  DISCORD_ZONE="$zone" \
  DISCORD_PAGE_GROUP="$DELIVERY_TARGETS" \
  DISCORD_TIME="$event_time" \
  DISCORD_TRIGGER_SOURCE="$trigger_source" \
  DISCORD_TRIGGER_EXTENSION="$trigger_extension" \
  DISCORD_TRIGGER_NAME="$trigger_name" \
  DISCORD_AUDIO_SEQUENCE="$audio_sequence" \
  python3 - <<'PY'
import json
import os
import sys
import urllib.error
import urllib.request

webhook = os.environ.get("DISCORD_WEBHOOK_URL", "").strip()
if not webhook:
    raise SystemExit(0)

fields = [
    ("Type", os.environ.get("DISCORD_TYPE", "")),
    ("Event", os.environ.get("DISCORD_EVENT", "")),
    ("Severity", os.environ.get("DISCORD_SEVERITY", "")),
    ("Message Type", os.environ.get("DISCORD_MESSAGE_TYPE", "")),
    ("Audio", os.environ.get("DISCORD_AUDIO", "")),
    ("NWS Recipients", os.environ.get("DISCORD_PAGE_GROUP", "")),
    ("Alert ID", os.environ.get("DISCORD_ALERT_ID", "")),
    ("Zone", os.environ.get("DISCORD_ZONE", "")),
    ("Trigger Source", os.environ.get("DISCORD_TRIGGER_SOURCE", "")),
    ("Trigger Extension", os.environ.get("DISCORD_TRIGGER_EXTENSION", "")),
    ("Trigger Name", os.environ.get("DISCORD_TRIGGER_NAME", "")),
    ("Audio Sequence", os.environ.get("DISCORD_AUDIO_SEQUENCE", "")),
    ("Time", os.environ.get("DISCORD_TIME", "")),
]
embed_fields = [
    {"name": name, "value": value[:1024], "inline": len(value) <= 32}
    for name, value in fields
    if value
]

body = os.environ.get("DISCORD_BODY", "")
description = body[:4096] if body else os.environ.get("DISCORD_SUBJECT", "")
payload = {
    "embeds": [
        {
            "title": "NWS Weather Alert",
            "description": description,
            "color": 0x7B2CBF,
            "fields": embed_fields[:25],
            "footer": {"text": "Southland Servers PBX - Purple and Gold Alert Routing"},
        }
    ],
}

request = urllib.request.Request(
    webhook,
    data=json.dumps(payload).encode("utf-8"),
    headers={"Content-Type": "application/json", "User-Agent": "SouthlandServersPBX-NWSAlerts/1.0"},
    method="POST",
)
try:
    with urllib.request.urlopen(request, timeout=12) as response:
        if response.status not in (200, 204):
            print(f"Discord webhook returned HTTP {response.status}", file=sys.stderr)
            raise SystemExit(1)
except urllib.error.HTTPError as exc:
    print(f"Discord webhook HTTP error {exc.code}: {exc.read().decode('utf-8', 'replace')}", file=sys.stderr)
    raise SystemExit(1)
except Exception as exc:
    print(f"Discord webhook error: {exc}", file=sys.stderr)
    raise SystemExit(1)
PY
}

touch "$SEEN_ALERTS" "$PROCESSED_ALERTS"

# Clear seen alerts file every 24 hours
LAST_CLEAR_FILE="/var/tmp/nws_last_clear.ts"
NOW_TS=$(date +%s)
LAST_CLEAR=$(cat "$LAST_CLEAR_FILE" 2>/dev/null || echo 0)
if [ $((NOW_TS - LAST_CLEAR)) -ge 86400 ]; then
  echo "$(date): Clearing alert history files (24h maintenance)" >> "$LOG"
  cp /dev/null "$SEEN_ALERTS"
  cp /dev/null "$PROCESSED_ALERTS"
  echo "$NOW_TS" > "$LAST_CLEAR_FILE"
fi

echo "$(date): Checking NWS alerts for zone $NWS_ZONE" >> "$LOG"

# Fetch active alerts from NWS unless a test payload is provided
if [ -n "$TEST_PAYLOAD" ]; then
  if [ ! -f "$TEST_PAYLOAD" ]; then
    echo "$(date): ERROR — Test payload not found: $TEST_PAYLOAD" >> "$LOG"
    report_fault "payload" "Test payload not found" "" "$TEST_PAYLOAD"
    exit 1
  fi
  ALERTS=$(cat "$TEST_PAYLOAD")
  echo "$(date): Using test payload from $TEST_PAYLOAD" >> "$LOG"
else
  ALERTS=$(curl -fsS --connect-timeout 10 --max-time 30 \
    -H "Accept: application/geo+json" \
    -H "User-Agent: FreePBX-WeatherAlert/1.0 (admin@yourdomain.com)" \
    "${NWS_API_BASE_URL%/}/alerts/active?zone=${NWS_ZONE}&status=actual" 2>>"$LOG") || ALERTS=""
fi

if [ -z "$ALERTS" ]; then
  echo "$(date): No response from NWS API" >> "$LOG"
  record_api_failure "No response from NWS API"
  exit 1
fi

PARSED_ALERTS="$(echo "$ALERTS" | python3 -c "
import base64
import sys, json

try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(2)

features = data.get('features', [])

if not features:
    sys.exit(0)

for feature in features:
    props = feature.get('properties', {})
    alert_id = (feature.get('id') or props.get('id') or '').strip().split('/')[-1]
    event = props.get('event', '').strip()
    severity = props.get('severity', '').strip()
    status = props.get('status', '').strip()
    msg_type = props.get('messageType', '').strip()
    references = props.get('references') or []
    reference_id = ''

    for ref in references:
        if not isinstance(ref, dict):
            continue
        if (ref.get('event') or '').strip() == event:
            reference_id = (ref.get('identifier') or '').strip().split('/')[-1]
            if reference_id:
                break
    if not reference_id:
        for ref in references:
            if isinstance(ref, dict):
                reference_id = (ref.get('identifier') or '').strip().split('/')[-1]
                if reference_id:
                    break

    if status != 'Actual':
        continue
    if msg_type == 'Cancel':
        continue

    key_source = reference_id or alert_id
    # Alert key excludes msg_type to treat updates as the same alert chain.
    alert_key = f'{event}|{severity}|{key_source}'
    alert_b64 = base64.b64encode(json.dumps(feature, separators=(',', ':'), ensure_ascii=True).encode('utf-8')).decode('ascii')
    values = [alert_id, event, severity, msg_type, alert_key, alert_b64]
    print('\t'.join(value.replace('\t', ' ') for value in values))
")"
PARSE_RC=$?

if [ $PARSE_RC -eq 2 ]; then
  echo "$(date): ERROR — Invalid JSON returned from NWS API" >> "$LOG"
  record_api_failure "Invalid JSON returned from NWS API"
  exit 1
fi

NOW_STATUS_TS="$(timestamp_now)"
update_status "$(printf '{"last_poll_at":%s,"last_poll_status":"ok","last_poll_message":"NWS poll completed successfully.","last_poll_ok_at":%s,"last_poll_fail_count":0,"last_poll_fail_started_at":""}' \
  "$(json_string "$NOW_STATUS_TS")" \
  "$(json_string "$NOW_STATUS_TS")")"
clear_fault_state

# Parse alerts
printf '%s\n' "$PARSED_ALERTS" | while IFS=$'\t' read -r ALERT_ID EVENT SEVERITY MSG_TYPE ALERT_KEY ALERT_B64; do

  [ -z "$ALERT_ID" ] && continue
  [ -z "$ALERT_KEY" ] && ALERT_KEY="${EVENT}|${SEVERITY}|${ALERT_ID}"

  # Skip already processed alert chains. NWS time-only updates can arrive with
  # a new alert ID, but references keep them tied to the original alert.
  if [ "$FORCE_REPLAY" != "1" ] && grep -qFx "$ALERT_KEY" "$PROCESSED_ALERTS"; then
    echo "$(date): Skipping update for already processed alert — Event: $EVENT | Key: $ALERT_KEY" >> "$LOG"
    continue
  fi

  QUIET_SUPPRESS_PAGING=0
  if quiet_hours_active && ! quiet_hours_allows_event "$EVENT"; then
    QUIET_SUPPRESS_PAGING=1
    echo "$(date): Quiet hours active — paging suppressed for '$EVENT' (not configured as critical)" >> "$LOG"
  fi

  NOW_TS_VAL=`date +%s`
  EVENT_SAFE_VAL=`echo "$EVENT" | tr -dc "[:alnum:]_"`
  LAST_EVENT_TS="$(get_event_cooldown_ts "$EVENT_SAFE_VAL")"
  if [ "$MSG_TYPE" = "Update" ] && [ -n "$LAST_EVENT_TS" ]; then
    DIFF_VAL=`expr $NOW_TS_VAL - $LAST_EVENT_TS`
    if [ $DIFF_VAL -lt $((COOLDOWN_HOURS * 3600)) ]; then
      echo "`date`: Skipping $EVENT - already paged recently" >> "$LOG"
      [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
      continue
    fi
  fi
  echo "$(date): New alert — Event: $EVENT | Severity: $SEVERITY | Type: $MSG_TYPE" >> "$LOG"

  AUDIO_LABEL="Piper TTS"
  AUDIO_SEQUENCE=""
  TTS_FILE=""

  if [ -n "${ALERT_SOUNDS[$EVENT]+_}" ]; then
    echo "$(date): Matched supported NWS event — using Piper TTS" >> "$LOG"
  else
    echo "$(date): Skipping '$EVENT' — unsupported event for Mass Notifications audio (severity: $SEVERITY)" >> "$LOG"
    [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
    continue
  fi

  if [ "$QUIET_SUPPRESS_PAGING" = "1" ]; then
    QUIET_TS="$(timestamp_now)"
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

    update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"skipped_quiet_hours","last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s,"last_delivery_alert_id":%s}' \
      "$(json_string "$QUIET_TS")" \
      "$(json_string "$EVENT")" \
      "$(json_string "$QUIET_AUDIO")" \
      "$(json_string "Sent email/webhook for ${EVENT}; paging suppressed by quiet hours.")" \
      "$(json_string "$DELIVERY_TARGETS")" \
      "$(json_string "$ALERT_ID")")"
    if ! send_notification_email "$MAIL_SUBJECT" "$MAIL_BODY"; then
      echo "$(date): ERROR — Quiet-hours alert email failed for $EVENT" >> "$LOG"
      report_fault "email" "Quiet-hours alert email failed" "$EVENT" "$ALERT_ID"
    fi
    if ! send_discord_alert "$MAIL_SUBJECT" "$MAIL_BODY" "Live NWS Alert (Quiet Hours)" "$EVENT" "$SEVERITY" "$MSG_TYPE" "$QUIET_AUDIO" "$ALERT_ID" "$NWS_ZONE" "$CURRENT_TIME" "NWS API" "" "" "$QUIET_AUDIO"; then
      echo "$(date): ERROR — Quiet-hours alert Discord webhook failed for $EVENT" >> "$LOG"
      report_fault "discord" "Quiet-hours alert Discord webhook failed" "$EVENT" "$ALERT_ID"
    fi
    append_event_log "$EVENT" "$SEVERITY" "$MSG_TYPE" "$QUIET_AUDIO" "$ALERT_ID" "$MAIL_SUBJECT" "$MAIL_BODY" "suppressed_quiet_hours"
    [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
    continue
  fi

  TTS_FILE="$(generate_tts_audio "$ALERT_B64" "$EVENT" "$ALERT_ID")"
  if [ -z "$TTS_FILE" ]; then
    echo "$(date): ERROR — Piper TTS audio was not generated for $EVENT" >> "$LOG"
    report_fault "audio" "Piper TTS audio was not generated" "$EVENT" "$ALERT_ID"
    [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
    continue
  fi

  AUDIO_SEQUENCE="$(build_audio_sequence "$TTS_FILE")"
  if [ -z "$AUDIO_SEQUENCE" ]; then
    echo "$(date): ERROR — Unable to build audio sequence for $EVENT" >> "$LOG"
    report_fault "audio" "Unable to build Piper TTS audio sequence" "$EVENT" "$ALERT_ID"
    [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
    continue
  fi

  echo "$(date): Queueing call file for $EVENT — audio sequence: $AUDIO_SEQUENCE" >> "$LOG"

  # Build call file
  if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
    echo "$(date): Dry run — would queue call files for $EVENT using $AUDIO_SEQUENCE to recipients: ${NWS_ALERT_RECIPIENTS[*]}" >> "$LOG"
  else
    if ! queue_audio_to_recipients "$AUDIO_SEQUENCE" "$EVENT" "$ALERT_ID"; then
      continue
    fi
  fi

  echo "$(date): Alert audio queued for $EVENT" >> "$LOG"
  if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
    echo "$(date): Dry run — would queue visual live alert for $EVENT" >> "$LOG"
  else
    trigger_visual_alert "$ALERT_B64" "$EVENT" "$ALERT_ID"
  fi
  set_event_cooldown_ts "$EVENT_SAFE_VAL" "$NOW_TS_VAL"
  DELIVERY_TS="$(timestamp_now)"
  update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"queued","last_delivery_source":"nws","last_delivery_event":%s,"last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s,"last_delivery_alert_id":%s}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$EVENT")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$AUDIO_LABEL")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Queued live NWS alert for ${EVENT} using Piper TTS")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TARGETS")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$ALERT_ID")")"
  [ "$FORCE_REPLAY" = "1" ] || mark_processed_alert "$ALERT_KEY"
  clear_fault_state

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
  if ! send_notification_email "$MAIL_SUBJECT" "$MAIL_BODY"; then
    echo "$(date): ERROR — Live alert email failed for $EVENT" >> "$LOG"
    report_fault "email" "Live alert email failed" "$EVENT" "$ALERT_ID"
  fi
  if ! send_discord_alert "$MAIL_SUBJECT" "$MAIL_BODY" "Live NWS Alert" "$EVENT" "$SEVERITY" "$MSG_TYPE" "$AUDIO_LABEL" "$ALERT_ID" "$NWS_ZONE" "$CURRENT_TIME" "NWS API" "" "" "$AUDIO_SEQUENCE"; then
    echo "$(date): ERROR — Live alert Discord webhook failed for $EVENT" >> "$LOG"
    report_fault "discord" "Live alert Discord webhook failed" "$EVENT" "$ALERT_ID"
  fi
  append_event_log "$EVENT" "$SEVERITY" "$MSG_TYPE" "$AUDIO_LABEL" "$ALERT_ID" "$MAIL_SUBJECT" "$MAIL_BODY" "triggered" "$AUDIO_SEQUENCE"

done

echo "$(date): Alert check complete" >> "$LOG"
