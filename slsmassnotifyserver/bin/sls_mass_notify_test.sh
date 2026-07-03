#!/bin/bash
# Southland Servers Mass Notifications Server by the Southland Servers Group

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
PIPER_MAX_SECONDS="20"
LOG="${LOG:-/var/log/sls_mass_notify.log}"
EVENTS_LOG="${EVENTS_LOG:-/var/log/sls_mass_notify_events.jsonl}"
LOG_RETENTION_DAYS="${LOG_RETENTION_DAYS:-90}"
CONFIG_FILE="${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.conf}"
COOLDOWN_FILE="${COOLDOWN_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/test-cooldown.ts}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json}"
FAULT_STATE_FILE="${FAULT_STATE_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/fault.state}"
COOLDOWN_SECONDS=60
SPOOL="${SPOOL:-/var/spool/asterisk/outgoing}"
MAIL_TO=""
DISCORD_WEBHOOK_URL=""
MAIL_FROM_NAME="SLS Mass Notification System"
MAIL_FROM_ADDR="no-reply@localhost"
SENDMAIL_BIN="/usr/sbin/sendmail"
SOURCE_EXTENSION=""
SOURCE_NAME="SLS Mass Notification System"
DELIVERY_TARGETS=""
TRIGGER_EXTENSION="${1:-unknown}"
TRIGGER_NAME="${2:-Unknown Caller}"
NWS_ALERTS_DRY_RUN="${NWS_ALERTS_DRY_RUN:-0}"
TEST_EMAIL_SUBJECT="Southland Servers Mass Notifications Server: NWS test triggered"
TEST_EMAIL_BODY="An NWS test was triggered.

Source Name: {{source_name}}
Trigger Source: {{trigger_source}}
Trigger Extension: {{trigger_extension}}
Trigger Name: {{trigger_name}}
NWS Recipients: {{page_group}}
Audio Sequence: {{audio_sequence}}
Time: {{time}}"

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
  local now
  local fault_key
  local subject
  local body

  now="$(timestamp_now)"
  update_status "$(printf '{"last_fault_at":%s,"last_fault_stage":%s,"last_fault_message":%s}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$now")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$stage")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$message")")"

  fault_key="${stage}|${message}"
  if [ -r "$FAULT_STATE_FILE" ] && [ "$(cat "$FAULT_STATE_FILE" 2>/dev/null)" = "$fault_key" ]; then
    return 1
  fi

  subject="Southland Servers Group PBX: EAS fault detected - ${stage}"
  body="A fault was detected in the EAS alert system.

Stage: ${stage}
Message: ${message}
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
  update_status '{"last_fault_at":"","last_fault_stage":"","last_fault_message":"","fault_email_sent_at":""}'
}

append_event_log() {
  local subject="$1"
  local body="$2"
  local audio_sequence="$3"
  local event_id

  event_id="test-$(date +%Y%m%d%H%M%S)-$$-$RANDOM"

  EVENT_ID="$event_id" \
  AUDIO_SEQUENCE="$audio_sequence" \
  SUBJECT="$subject" \
  BODY="$body" \
  NWS_RECIPIENTS="$DELIVERY_TARGETS" \
  SOURCE_EXTENSION="$SOURCE_EXTENSION" \
  SOURCE_NAME="$SOURCE_NAME" \
  TRIGGER_EXTENSION="$TRIGGER_EXTENSION" \
  TRIGGER_NAME="$TRIGGER_NAME" \
  EVENTS_LOG_PATH="$EVENTS_LOG" \
  python3 - <<'PY'
import json
import os
from datetime import datetime, timezone

payload = {
    "event_id": os.environ["EVENT_ID"],
    "logged_at": datetime.now(timezone.utc).astimezone().isoformat(),
    "type": "test",
    "status": "triggered",
    "system_name": os.environ.get("SOURCE_NAME", ""),
    "source_extension": os.environ.get("SOURCE_EXTENSION", ""),
    "source_name": os.environ.get("SOURCE_NAME", ""),
    "trigger_source": "FreePBX Dashboard",
    "trigger_extension": os.environ.get("TRIGGER_EXTENSION", ""),
    "trigger_name": os.environ.get("TRIGGER_NAME", ""),
    "page_group": os.environ.get("NWS_RECIPIENTS", ""),
    "event": "Manual NWS Paging Test",
    "severity": "Test",
    "message_type": "Test",
    "audio": "Piper TTS",
    "audio_sequence": [part for part in os.environ.get("AUDIO_SEQUENCE", "").split("&") if part],
    "mail_subject": os.environ.get("SUBJECT", ""),
    "mail_body": os.environ.get("BODY", ""),
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

generate_test_tts_audio() {
  local base_name
  local tmp_file
  local output_file
  local trimmed_file
  local duration
  local text

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

  text="This is a test of the Southland Servers Mass Notification weather alert system. No action is required."
  base_name="test_piper_tts_$(date +%Y%m%d%H%M%S)_$$"
  tmp_file="$(mktemp /tmp/sls_test_tts_XXXXXX.wav)"
  output_file="${SLS_TTS_DIR}/${base_name}.wav"

  if ! printf '%s\n' "$text" | timeout 25 "$PIPER_BIN" --model "${PIPER_NWS_VOICE:-$PIPER_VOICE}" --volume "1.00" --output-file "$tmp_file" >> "$LOG" 2>&1; then
    rm -f "$tmp_file"
    echo "$(date): ERROR — Piper TTS generation failed for manual test" >> "$LOG"
    return 1
  fi

  if command -v sox >/dev/null 2>&1; then
    if ! sox -v "${PIPER_NWS_VOLUME:-0.85}" "$tmp_file" -r 8000 -c 1 -b 16 "$output_file" >> "$LOG" 2>&1; then
      rm -f "$tmp_file" "$output_file"
      echo "$(date): ERROR — Unable to convert manual test Piper TTS WAV" >> "$LOG"
      return 1
    fi
  else
    mv "$tmp_file" "$output_file"
    tmp_file=""
  fi
  rm -f "$tmp_file"

  if command -v soxi >/dev/null 2>&1; then
    duration="$(soxi -D "$output_file" 2>/dev/null || echo 0)"
    if awk "BEGIN { exit !($duration > ${PIPER_MAX_SECONDS:-20}) }"; then
      trimmed_file="${output_file}.trimmed"
      if sox "$output_file" "$trimmed_file" trim 0 "${PIPER_MAX_SECONDS:-20}" >> "$LOG" 2>&1; then
        mv "$trimmed_file" "$output_file"
      else
        rm -f "$trimmed_file"
      fi
    fi
  fi

  chown asterisk:asterisk "$output_file" 2>/dev/null || true
  chmod 644 "$output_file"
  echo "$(date): Generated manual test Piper TTS — ${base_name}.wav" >> "$LOG"
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
    combined_base="test_sequence_${tts_base}"
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

trigger_visual_test() {
	local event
	local severity
	local description
	local test_id
	local targets

  if [ ! -x /usr/local/bin/sls_mass_notify/sls_notify.py ]; then
    echo "$(date): Visual test skipped — /usr/local/bin/sls_mass_notify/sls_notify.py is not executable" >> "$LOG"
    return 0
  fi

  event="Manual NWS Test"
  severity="Test"
  test_id="pbx-gui-test-$(date +%Y%m%d%H%M%S)-$$"
  description="PBX TEST - Simulated ${event}. This visual alert was triggered from the FreePBX Mass Notifications testing page."
  targets="$(get_nws_recipient_targets)"
  if [ -z "$targets" ]; then
    echo "$(date): Visual test skipped — no NWS recipient extensions configured" >> "$LOG"
    return 0
  fi

  echo "$(date): Queueing visual test image — Event: $event | Audio: $sound" >> "$LOG"
  nohup /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py \
    --event "$event" \
    --severity "$severity" \
    --area "Williamson County TX" \
    --description "$description" \
    --test-id "$test_id" \
    --targets "$targets" \
    >> "$LOG" 2>&1 &
}

get_nws_recipient_targets() {
  local IFS=,
  printf '%s\n' "${NWS_ALERT_RECIPIENTS[*]}"
}

queue_test_audio_to_recipients() {
  local sound_sequence="$1"
  local recipient
  local callfile
  local queued=0

  if [ "${#NWS_ALERT_RECIPIENTS[@]}" -eq 0 ]; then
    echo "$(date): ERROR — No NWS alert recipient extensions configured" >> "$LOG"
    report_fault "delivery" "No NWS alert recipient extensions configured"
    return 1
  fi

  for recipient in "${NWS_ALERT_RECIPIENTS[@]}"; do
    recipient="$(printf '%s' "$recipient" | tr -dc '0-9')"
    [ -n "$recipient" ] || continue
    callfile=$(mktemp /tmp/sls_test_XXXXXX.call)
    cat > "$callfile" << CALL
Channel: Local/${recipient}@${SLS_AUDIO_CONTEXT}
CallerID: "${SLS_CALLERID_NAME}" <${SLS_CALLERID_NUM}>
Setvar: SLS_SOUND=${sound_sequence}
Setvar: SLS_CALLERID_NAME=${SLS_CALLERID_NAME}
Setvar: SLS_CALLERID_NUM=${SLS_CALLERID_NUM}
MaxRetries: 0
RetryTime: 5
WaitTime: 180
Application: Wait
Data: 1
CALL
    chown asterisk:asterisk "$callfile" 2>/dev/null || true
    chmod 777 "$callfile"
    if ! mv "$callfile" "$SPOOL/"; then
      echo "$(date): ERROR — Unable to move test call file for $recipient into $SPOOL" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      continue
    fi
    queued=$((queued + 1))
  done

  if [ "$queued" -eq 0 ]; then
    report_fault "delivery" "Unable to queue test calls to configured NWS recipients"
    return 1
  fi

  echo "$(date): Test call files queued to $queued recipient(s) — $sound_sequence" >> "$LOG"
  return 0
}

if [ -r "$CONFIG_FILE" ]; then
  # Generated by the FreePBX Mass Notifications settings page.
  . "$CONFIG_FILE"
fi
prune_event_log
DELIVERY_TARGETS="$(get_nws_recipient_targets)"

if [ "${NWS_ALERTS_ENABLED:-1}" != "1" ]; then
  echo "$(date): NWS alerts are disabled in settings; manual test skipped" >> "$LOG"
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"skipped","last_test_message":"Manual test skipped because alerts are disabled."}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")")"
  exit 0
fi

prune_tts_cache

LAST_RUN=0
if [ -r "$COOLDOWN_FILE" ]; then
  LAST_RUN="$(tr -dc '0-9' < "$COOLDOWN_FILE")"
fi
if [ -z "$LAST_RUN" ]; then
  LAST_RUN=0
fi

NOW_TS="$(date +%s)"
if [ "$LAST_RUN" -gt 0 ] && [ $((NOW_TS - LAST_RUN)) -lt "$COOLDOWN_SECONDS" ]; then
  REMAINING=$((COOLDOWN_SECONDS - (NOW_TS - LAST_RUN)))
  echo "$(date): Manual test blocked by cooldown — ${REMAINING}s remaining" >> "$LOG"
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"cooldown","last_test_message":%s}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Manual test blocked by cooldown (${REMAINING}s remaining).")")"
  exit 0
fi

printf '%s\n' "$NOW_TS" > "$COOLDOWN_FILE"
chmod 644 "$COOLDOWN_FILE" 2>/dev/null || true
chown asterisk:asterisk "$COOLDOWN_FILE" 2>/dev/null || true

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
            "footer": {"text": "Southland Servers PBX • Purple and Gold Alert Routing"},
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

# Wait for phone to hang up before paging
sleep 3

echo "$(date): Manual Piper TTS test alert triggered" >> "$LOG"

TTS_FILE="$(generate_test_tts_audio)"
if [ -z "$TTS_FILE" ]; then
  echo "ERROR: Piper TTS test audio was not generated"
  report_fault "audio" "Piper TTS test audio was not generated"
  exit 1
fi

AUDIO_SEQUENCE="$(build_audio_sequence "$TTS_FILE")"
if [ -z "$AUDIO_SEQUENCE" ]; then
  echo "ERROR: Piper TTS test audio sequence was not generated"
  report_fault "audio" "Piper TTS test audio sequence was not generated"
  exit 1
fi

echo "Playing: $AUDIO_SEQUENCE"
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  echo "$(date): Dry run — would queue visual test image" >> "$LOG"
else
  trigger_visual_test "Piper_TTS"
fi

# Build call files using the same direct audio path as live NWS alerts.
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  echo "$(date): Dry run — would queue test call files using $AUDIO_SEQUENCE to recipients: ${NWS_ALERT_RECIPIENTS[*]}" >> "$LOG"
else
  if ! queue_test_audio_to_recipients "$AUDIO_SEQUENCE"; then
    exit 1
  fi
fi

echo "$(date): Test audio queued — $AUDIO_SEQUENCE" >> "$LOG"
DELIVERY_TS="$(timestamp_now)"
update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":"queued","last_delivery_source":"test","last_delivery_event":"Manual NWS Test","last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s}' \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Piper TTS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Queued manual Piper TTS test")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TARGETS")")"
update_status "$(printf '{"last_test_at":%s,"last_test_status":"queued","last_test_message":%s,"last_test_audio":%s}' \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Queued manual Piper TTS test")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Piper TTS")")"
clear_fault_state
CURRENT_TIME="$(date)"
MAIL_SUBJECT="$(render_template "$TEST_EMAIL_SUBJECT" \
  "event=Manual NWS Test" \
  "severity=Test" \
  "message_type=Test" \
  "audio=Piper TTS" \
  "page_group=$DELIVERY_TARGETS" \
  "alert_id=" \
  "zone=" \
  "time=$CURRENT_TIME" \
  "source_extension=$SOURCE_EXTENSION" \
  "source_name=$SOURCE_NAME" \
  "trigger_source=FreePBX Dashboard" \
  "trigger_extension=$TRIGGER_EXTENSION" \
  "trigger_name=$TRIGGER_NAME" \
  "audio_sequence=${AUDIO_SEQUENCE}")"
MAIL_BODY="$(render_template "$TEST_EMAIL_BODY" \
  "event=Manual NWS Test" \
  "severity=Test" \
  "message_type=Test" \
  "audio=Piper TTS" \
  "page_group=$DELIVERY_TARGETS" \
  "alert_id=" \
  "zone=" \
  "time=$CURRENT_TIME" \
  "source_extension=$SOURCE_EXTENSION" \
  "source_name=$SOURCE_NAME" \
  "trigger_source=FreePBX Dashboard" \
  "trigger_extension=$TRIGGER_EXTENSION" \
  "trigger_name=$TRIGGER_NAME" \
  "audio_sequence=${AUDIO_SEQUENCE}")"
if ! send_notification_email "$MAIL_SUBJECT" "$MAIL_BODY"; then
  echo "$(date): ERROR — Test email failed" >> "$LOG"
  report_fault "email" "Manual test email failed"
fi
if ! send_discord_alert "$MAIL_SUBJECT" "$MAIL_BODY" "Manual Test" "Manual NWS Test" "Test" "Test" "Piper TTS" "" "" "$CURRENT_TIME" "FreePBX Dashboard" "$TRIGGER_EXTENSION" "$TRIGGER_NAME" "$AUDIO_SEQUENCE"; then
  echo "$(date): ERROR — Test Discord webhook failed" >> "$LOG"
  report_fault "discord" "Manual test Discord webhook failed"
fi
append_event_log "$MAIL_SUBJECT" "$MAIL_BODY" "$AUDIO_SEQUENCE"
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  echo "Dry run complete. No phones were notified."
else
  echo "Done! Check your phones."
fi
