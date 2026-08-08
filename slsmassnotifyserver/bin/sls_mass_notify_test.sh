#!/bin/bash
# Southland Servers Mass Notifications Server by the Southland Servers Group

usage() {
  printf '%s\n' 'Usage: sls_mass_notify_test.sh [trigger-id [trigger-name]]'
  printf '%s\n' 'Run a manual Weather Alert delivery test. Invoke this through the authenticated FreePBX interface.'
}

if [ "$#" -eq 1 ] && { [ "$1" = "-h" ] || [ "$1" = "--help" ]; }; then
  usage
  exit 0
fi
if [ "$#" -gt 2 ]; then
  printf 'Too many arguments.\n' >&2
  usage >&2
  exit 2
fi
case "${1:-}" in
  -*)
    printf 'Unknown option: %s\n' "$1" >&2
    usage >&2
    exit 2
    ;;
esac
case "${2:-}" in
  -*)
    printf 'Unknown option: %s\n' "$2" >&2
    usage >&2
    exit 2
    ;;
esac

SLS_CALLERID_NAME="SLS Mass Notification System"
SLS_CALLERID_NUM="SLS"
SLS_AUDIO_CONTEXT="sls-alert-audio"
NWS_ALERT_RECIPIENTS=()
SLS_TONE_SOUND_PREFIX="SLS_Mass_Notifications_Plugin/tones"
SLS_TTS_SOUND_PREFIX="SLS_Mass_Notifications_Plugin/tts"
SLS_TONES_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tones"
SLS_TTS_DIR="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/sounds/tts"
SLS_OPENING_TONE="opening_NWS_alert"
SLS_CLOSING_TONE=""
PIPER_BIN="/usr/local/bin/sls_mass_notify/piper/venv/bin/piper"
PIPER_VOICE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx"
PIPER_NWS_VOICE="/var/lib/asterisk/SLS_Mass_Notifications_Plugin/piper/voices/en_US-lessac-low.onnx"
PIPER_NWS_VOLUME="0.25"
PIPER_MAX_SECONDS="30"
LOG="${LOG:-/var/log/sls_mass_notify.log}"
EVENTS_LOG="${EVENTS_LOG:-/var/log/sls_mass_notify_events.jsonl}"
LOG_RETENTION_DAYS="${LOG_RETENTION_DAYS:-90}"
CONFIG_JSON_FILE="${CONFIG_JSON_FILE:-${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}}"
CONFIG_LOADER="${CONFIG_LOADER:-/usr/local/bin/sls_mass_notify/sls_config.py}"
BRANDED_EMAIL_SCRIPT="${BRANDED_EMAIL_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_branded_email.py}"
BRANDED_DISCORD_SCRIPT="${BRANDED_DISCORD_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_branded_discord.py}"
COOLDOWN_FILE="${COOLDOWN_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/test-cooldown.ts}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json}"
FAULT_STATE_FILE="${FAULT_STATE_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/fault.state}"
COOLDOWN_SECONDS=60
SPOOL="${SPOOL:-/var/spool/asterisk/outgoing}"
SPOOL_TMP="${SPOOL_TMP:-/var/spool/asterisk/tmp}"
SPOOL_DONE="${SPOOL_DONE:-/var/spool/asterisk/outgoing_done}"
TEST_CALL_RESULTS=()
MAIL_TO=""
DISCORD_WEBHOOK_URL=""
MAIL_FROM_NAME="SLS Mass Notification System"
MAIL_FROM_ADDR="no-reply@localhost.localdomain"
EMAIL_HTML_ENABLED="1"
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
    os.chmod(path, 0o640)
except FileNotFoundError:
    raise SystemExit(0)
PY
}

update_status() {
  local patch_json="$1"

  STATUS_FILE_PATH="$STATUS_FILE" \
  STATUS_PATCH_JSON="$patch_json" \
  python3 - <<'PY'
import fcntl
import json
import os

path = os.environ["STATUS_FILE_PATH"]
patch = json.loads(os.environ["STATUS_PATCH_JSON"])

os.makedirs(os.path.dirname(path), mode=0o750, exist_ok=True)
with open(path, "a+", encoding="utf-8") as handle:
    fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
    handle.seek(0)
    try:
        loaded = json.load(handle)
        data = loaded if isinstance(loaded, dict) else {}
    except Exception:
        data = {}
    data.update(patch)
    handle.seek(0)
    handle.truncate(0)
    json.dump(data, handle, indent=2, sort_keys=True)
    handle.write("\n")
    handle.flush()
    os.fsync(handle.fileno())
    fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
os.chmod(path, 0o640)
PY

  chmod 0640 "$STATUS_FILE" 2>/dev/null || true
  chown asterisk:asterisk "$STATUS_FILE" 2>/dev/null || true
}

report_fault() {
  local stage="$1"
  local message="$2"
  local now

  now="$(timestamp_now)"
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"fault","last_test_stage":%s,"last_test_message":%s}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$now")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$stage")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$message")")"
  return 0
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
  EVENT_STATUS="$TEST_DELIVERY_STATUS" \
  EVENTS_LOG_PATH="$EVENTS_LOG" \
  python3 - <<'PY'
import fcntl
import json
import os
from datetime import datetime, timezone

payload = {
    "event_id": os.environ["EVENT_ID"],
    "logged_at": datetime.now(timezone.utc).astimezone().isoformat(),
    "type": "test",
    "status": os.environ.get("EVENT_STATUS", "triggered"),
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

generate_test_tts_audio() {
  local base_name
  local tmp_file
  local output_file
  local trimmed_file
  local duration
  local text
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

  text="This is a test of the Southland Servers Mass Notification weather alert system. No action is required."
  base_name="test_piper_tts_$(date +%Y%m%d%H%M%S)_$$"
  tmp_file="$(mktemp /tmp/sls_test_tts_XXXXXX.wav)"
  output_file="${SLS_TTS_DIR}/${base_name}.wav"

  generation_timeout=$((PIPER_MAX_SECONDS * 2 + 30))
  [ "$generation_timeout" -lt 25 ] && generation_timeout=25
  [ "$generation_timeout" -gt 900 ] && generation_timeout=900
  if ! printf '%s\n' "$text" | timeout "$generation_timeout" "$PIPER_BIN" --model "${PIPER_NWS_VOICE:-$PIPER_VOICE}" --volume "1.00" --output-file "$tmp_file" >> "$LOG" 2>&1; then
    rm -f "$tmp_file"
    echo "$(date): ERROR — Piper TTS generation failed for manual test" >> "$LOG"
    return 1
  fi

  if command -v sox >/dev/null 2>&1; then
    if ! sox -v "${PIPER_NWS_VOLUME:-0.25}" "$tmp_file" -r 8000 -c 1 -b 16 "$output_file" >> "$LOG" 2>&1; then
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
    find "$SLS_TTS_DIR" -maxdepth 1 -type f -name '*.wav' -mmin +15 -delete 2>/dev/null || true
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
    return 1
  fi

  event="Manual NWS Test"
  severity="Test"
  test_id="pbx-gui-test-$(date +%Y%m%d%H%M%S)-$$"
  description="PBX TEST - Simulated ${event}. This visual alert was triggered from the FreePBX Mass Notifications testing page."
  targets="$(get_nws_recipient_targets)"
  if [ -z "$targets" ]; then
    echo "$(date): Visual test skipped — no NWS recipient extensions configured" >> "$LOG"
    return 1
  fi

  echo "$(date): Sending visual test image — Event: $event" >> "$LOG"
  if ! /usr/bin/timeout 45 /usr/bin/python3 /usr/local/bin/sls_mass_notify/sls_notify.py \
    --event "$event" \
    --severity "$severity" \
    --area "${NWS_ZONE:-Configured NWS zone}" \
    --description "$description" \
    --test-id "$test_id" \
    --targets "$targets" \
    --no-retry \
    >> "$LOG" 2>&1; then
    echo "$(date): ERROR — Visual test delivery failed" >> "$LOG"
    return 1
  fi
  return 0
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
    callfile=$(mktemp "$SPOOL_TMP/sls_test_XXXXXX.call")
    cat > "$callfile" << CALL
Channel: Local/${recipient}@${SLS_AUDIO_CONTEXT}
CallerID: "${SLS_CALLERID_NAME}" <${SLS_CALLERID_NUM}>
Setvar: SLS_SOUND=${sound_sequence}
Setvar: SLS_CALLERID_NAME=${SLS_CALLERID_NAME}
Setvar: SLS_CALLERID_NUM=${SLS_CALLERID_NUM}
MaxRetries: 0
RetryTime: 5
WaitTime: 30
Archive: yes
Application: Wait
Data: 1
CALL
    chown asterisk:asterisk "$callfile" 2>/dev/null || true
    chmod 0640 "$callfile"
    if ! mv "$callfile" "$SPOOL/"; then
      echo "$(date): ERROR — Unable to move test call file for $recipient into $SPOOL" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      continue
    fi
    TEST_CALL_RESULTS+=("$SPOOL_DONE/$(basename "$callfile")")
    queued=$((queued + 1))
  done

  if [ "$queued" -eq 0 ]; then
    report_fault "delivery" "Unable to queue test calls to configured NWS recipients"
    return 1
  fi

  echo "$(date): Test call files queued to $queued recipient(s) — $sound_sequence" >> "$LOG"
  return 0
}

wait_for_test_call_results() {
  local deadline=$((SECONDS + 45))
  local result_file
  local pending
  local status
  local failed=0

  [ "${#TEST_CALL_RESULTS[@]}" -gt 0 ] || return 1
  while [ "$SECONDS" -lt "$deadline" ]; do
    pending=0
    for result_file in "${TEST_CALL_RESULTS[@]}"; do
      [ -f "$result_file" ] || pending=$((pending + 1))
    done
    [ "$pending" -eq 0 ] && break
    sleep 1
  done

  for result_file in "${TEST_CALL_RESULTS[@]}"; do
    if [ ! -f "$result_file" ]; then
      echo "$(date): ERROR — Timed out waiting for Asterisk test call result $(basename "$result_file")" >> "$LOG"
      failed=1
      continue
    fi
    status="$(awk -F: 'tolower($1) == "status" {sub(/^[[:space:]]+/, "", $2); print $2; exit}' "$result_file")"
    if [ "$status" != "Completed" ]; then
      echo "$(date): ERROR — Asterisk test call $(basename "$result_file") completed with status ${status:-Unknown}" >> "$LOG"
      failed=1
    fi
    rm -f "$result_file" 2>/dev/null || true
  done
  [ "$failed" -eq 0 ]
}

load_central_config() {
  local dump_file
  local key
  local value

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
  while IFS= read -r -d '' key && IFS= read -r -d '' value; do
    case "$key" in
      NWS_ALERT_RECIPIENT) NWS_ALERT_RECIPIENTS+=("$value") ;;
      NWS_ALERTS_ENABLED|PUBLIC_PBX_HOST|NWS_API_BASE_URL|NWS_ZONE|SLS_OPENING_TONE|SLS_CLOSING_TONE|PIPER_BIN|PIPER_NWS_VOICE|PIPER_ANNOUNCEMENT_VOICE|PIPER_NWS_VOLUME|PIPER_ANNOUNCEMENT_VOLUME|PIPER_MAX_SECONDS|LOG_RETENTION_DAYS|MAIL_TO|DISCORD_WEBHOOK_URL|QUIET_HOURS_ENABLED|QUIET_HOURS_START|QUIET_HOURS_END|MAIL_FROM_NAME|MAIL_FROM_ADDR|ALERT_EMAIL_SUBJECT|ALERT_EMAIL_BODY|TEST_EMAIL_SUBJECT|TEST_EMAIL_BODY|EMAIL_HTML_ENABLED|AMI_USERNAME|AMI_PASSWORD|AMI_HOST|AMI_PORT|GITHUB_UPDATES_ENABLED|GITHUB_UPDATES_REPOSITORY|GITHUB_UPDATES_CHANNEL)
        printf -v "$key" '%s' "$value"
        ;;
    esac
  done < "$dump_file"
  rm -f "$dump_file"
  PIPER_VOICE="$PIPER_NWS_VOICE"
  return 0
}

if ! load_central_config; then
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"fault","last_test_message":"Central configuration is invalid or unavailable."}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")")"
	exit 1
fi
if [ -n "${NWS_ZONE_OVERRIDE:-}" ]; then
  NWS_ZONE="$NWS_ZONE_OVERRIDE"
fi
if [ -n "${NWS_RECIPIENTS_OVERRIDE:-}" ]; then
  IFS=',' read -r -a NWS_ALERT_RECIPIENTS <<< "$NWS_RECIPIENTS_OVERRIDE"
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
chmod 0640 "$COOLDOWN_FILE" 2>/dev/null || true
chown asterisk:asterisk "$COOLDOWN_FILE" 2>/dev/null || true

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

# Build call files using the same direct audio path as live NWS alerts.
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  echo "$(date): Dry run — would queue test call files using $AUDIO_SEQUENCE to recipients: ${NWS_ALERT_RECIPIENTS[*]}" >> "$LOG"
else
  mkdir -p "$SPOOL_DONE"
  chown asterisk:asterisk "$SPOOL_DONE" 2>/dev/null || true
  chmod 0750 "$SPOOL_DONE" 2>/dev/null || true
  if ! queue_test_audio_to_recipients "$AUDIO_SEQUENCE"; then
    exit 1
  fi
fi

# Let the auto-answer call enter media before placing the visual screen on top.
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  echo "$(date): Dry run — would send visual test image after audio starts" >> "$LOG"
else
  sleep 2
  if ! trigger_visual_test; then
    report_fault "visual" "Manual NWS test SIP NOTIFY delivery failed"
    exit 1
  fi
  if ! wait_for_test_call_results; then
    report_fault "delivery" "One or more manual NWS test calls did not complete"
    echo "ERROR: One or more Asterisk test calls did not complete. Confirm endpoint registration, DND, and phone auto-answer policy."
    exit 1
  fi
fi

echo "$(date): Test audio and SIP NOTIFY delivery checks completed — $AUDIO_SEQUENCE" >> "$LOG"
DELIVERY_TS="$(timestamp_now)"
TEST_DELIVERY_STATUS="completed"
TEST_DELIVERY_MESSAGE="Asterisk completed manual Piper TTS test calls and accepted SIP NOTIFY delivery"
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  TEST_DELIVERY_STATUS="dry_run"
  TEST_DELIVERY_MESSAGE="Dry run completed for manual Piper TTS test"
fi
update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":%s,"last_delivery_source":"test","last_delivery_event":"Manual NWS Test","last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s}' \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_STATUS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Piper TTS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_MESSAGE")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TARGETS")")"
update_status "$(printf '{"last_test_at":%s,"last_test_status":%s,"last_test_message":%s,"last_test_audio":%s}' \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_STATUS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_MESSAGE")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Piper TTS")")"
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
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  append_event_log "$MAIL_SUBJECT" "$MAIL_BODY" "$AUDIO_SEQUENCE"
  echo "Dry run complete. No phones were notified."
else
  append_event_log "$MAIL_SUBJECT" "$MAIL_BODY" "$AUDIO_SEQUENCE"
  echo "Weather test delivered to ${#NWS_ALERT_RECIPIENTS[@]} configured recipient(s). Email and Discord were not sent."
fi
