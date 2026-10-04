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
NWS_DESKTOP_CLIENTS=()
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
LOG="${LOG:-/var/log/sls_mass_notify.log}"
EVENTS_LOG="${EVENTS_LOG:-/var/log/sls_mass_notify_events.jsonl}"
LOG_RETENTION_DAYS="${LOG_RETENTION_DAYS:-90}"
CONFIG_JSON_FILE="${CONFIG_JSON_FILE:-${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}}"
CONFIG_LOADER="${CONFIG_LOADER:-/usr/local/bin/sls_mass_notify/sls_config.py}"
AUDIO_QUEUE_HELPER="/usr/local/bin/sls_mass_notify/sls_audio_queue.py"
VISUAL_PUSH_SCRIPT="${VISUAL_PUSH_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_notify.py}"
BRANDED_EMAIL_SCRIPT="${BRANDED_EMAIL_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_branded_email.py}"
BRANDED_DISCORD_SCRIPT="${BRANDED_DISCORD_SCRIPT:-/usr/local/bin/sls_mass_notify/sls_branded_discord.py}"
COOLDOWN_FILE="${COOLDOWN_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/test-cooldown.ts}"
STATUS_FILE="${STATUS_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/status.json}"
FAULT_STATE_FILE="${FAULT_STATE_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/fault.state}"
COOLDOWN_SECONDS=60
SPOOL="${SPOOL:-/var/spool/asterisk/outgoing}"
SPOOL_TMP="${SPOOL_TMP:-/var/spool/asterisk/tmp}"
TEST_CALL_QUEUE_PATHS=()
declare -A TEST_CALL_RECIPIENTS=()
declare -A TEST_CALL_ACCEPTED=()
TEST_CALL_QUEUE_FAILURES=0
TEST_SPOOL_PICKUP_TIMEOUT_SECONDS="${TEST_SPOOL_PICKUP_TIMEOUT_SECONDS:-10}"
ASTERISK_CLI_BIN="${ASTERISK_CLI_BIN:-$(command -v asterisk 2>/dev/null || true)}"
[ -n "$ASTERISK_CLI_BIN" ] || ASTERISK_CLI_BIN="/usr/sbin/asterisk"
MAIL_TO=""
DISCORD_WEBHOOK_URL=""
MAIL_FROM_NAME="SLS Mass Notification System"
MAIL_FROM_ADDR="no-reply@localhost.localdomain"
EMAIL_HTML_ENABLED="1"
SENDMAIL_BIN="/usr/sbin/sendmail"
SOURCE_EXTENSION=""
SOURCE_NAME="SLS Mass Notification System"
DELIVERY_TARGETS=""
DESKTOP_DELIVERY_TARGETS=""
TEST_AUDIO_LABEL="None"
VISUAL_TEST_ID="${SLS_TEST_DELIVERY_ID:-}"
API_TEST_REMOVED_RECIPIENTS=0
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

refresh_api_test_permissions() {
  # GUI/legacy tests retain their established behavior. Named API tests carry
  # only a frozen scope/identity snapshot; no bearer secret enters this worker.
  [ -n "${SLS_API_TEST_CONTEXT:-}" ] || return 0
  local output
  local -a allowed=()
  if ! output="$(/usr/bin/timeout --kill-after=1 5 /usr/bin/php /usr/local/bin/sls_mass_notify/sls_api_test_guard.php "$CONFIG_JSON_FILE")"; then
    NWS_ALERT_RECIPIENTS=()
    NWS_DESKTOP_CLIENTS=()
    printf '%s\n' 'ERROR: Weather test API authorization could not be verified. No further recipients will be submitted.' >&2
    return 1
  fi
  mapfile -t allowed <<< "$output"
  if [ "${#allowed[@]}" -ne 3 ] || [[ ! "${allowed[0]}" =~ ^([0-9]{1,20}(,[0-9]{1,20})*)?$ ]] \
      || [[ ! "${allowed[1]}" =~ ^([A-Za-z0-9_.@-]{1,80}(,[A-Za-z0-9_.@-]{1,80})*)?$ ]] \
      || [[ ! "${allowed[2]}" =~ ^[0-9]+$ ]]; then
    NWS_ALERT_RECIPIENTS=()
    NWS_DESKTOP_CLIENTS=()
    printf '%s\n' 'ERROR: Weather test authorization returned an invalid recipient list. No further recipients will be submitted.' >&2
    return 1
  fi
  NWS_ALERT_RECIPIENTS=()
  NWS_DESKTOP_CLIENTS=()
  [ -z "${allowed[0]}" ] || IFS=',' read -r -a NWS_ALERT_RECIPIENTS <<< "${allowed[0]}"
  [ -z "${allowed[1]}" ] || IFS=',' read -r -a NWS_DESKTOP_CLIENTS <<< "${allowed[1]}"
  if [ "${allowed[2]}" -gt 0 ]; then API_TEST_REMOVED_RECIPIENTS=1; fi
}

claim_test_cooldown() {
  COOLDOWN_FILE_PATH="$COOLDOWN_FILE" \
  COOLDOWN_SECONDS_VALUE="$COOLDOWN_SECONDS" \
  python3 - <<'PY'
import fcntl
import os
import stat
import sys
import time

path = os.environ["COOLDOWN_FILE_PATH"]
try:
    cooldown = max(1, min(3600, int(os.environ.get("COOLDOWN_SECONDS_VALUE", "60"))))
except ValueError:
    cooldown = 60

parent = os.path.dirname(path)
if not parent or not os.path.isdir(parent) or os.path.islink(parent):
    print("ERROR invalid cooldown directory")
    raise SystemExit(2)

lock_path = path + ".lock"
common_flags = os.O_RDWR | os.O_CREAT | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
lock_fd = os.open(lock_path, common_flags | getattr(os, "O_NONBLOCK", 0), 0o640)
try:
    if not stat.S_ISREG(os.fstat(lock_fd).st_mode):
        print("ERROR invalid cooldown lock")
        raise SystemExit(2)
    os.fchmod(lock_fd, 0o640)
    fcntl.flock(lock_fd, fcntl.LOCK_EX)

    cooldown_fd = os.open(path, common_flags | getattr(os, "O_NONBLOCK", 0), 0o640)
    try:
        if not stat.S_ISREG(os.fstat(cooldown_fd).st_mode):
            print("ERROR invalid cooldown state")
            raise SystemExit(2)
        os.fchmod(cooldown_fd, 0o640)
        raw = os.read(cooldown_fd, 64).decode("ascii", "ignore").strip()
        last_run = int(raw) if raw.isdigit() else 0
        now = int(time.time())
        remaining = min(cooldown, cooldown - (now - last_run))
        if last_run > 0 and remaining > 0:
            print(f"COOLDOWN {remaining}")
            raise SystemExit(3)
        os.lseek(cooldown_fd, 0, os.SEEK_SET)
        os.ftruncate(cooldown_fd, 0)
        os.write(cooldown_fd, f"{now}\n".encode("ascii"))
        os.fsync(cooldown_fd)
        print("CLAIMED")
    finally:
        os.close(cooldown_fd)
finally:
    os.close(lock_fd)
PY
}

# Periodic storage maintenance owns event retention and its recovery backups.

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
  DESKTOP_RECIPIENTS="$DESKTOP_DELIVERY_TARGETS" \
  TEST_AUDIO_LABEL="$TEST_AUDIO_LABEL" \
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
    "desktop_all": False,
    "desktop_clients": [item for item in os.environ.get("DESKTOP_RECIPIENTS", "").split(",") if item],
    "event": "Manual NWS Paging Test",
    "severity": "Test",
    "message_type": "Test",
    "audio": os.environ.get("TEST_AUDIO_LABEL", "None"),
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

  duration="$(soxi -D "$output_file" 2>/dev/null)"
  if ! [[ "$duration" =~ ^[0-9]+([.][0-9]+)?$ ]] || ! awk "BEGIN { exit !($duration > 0) }"; then
    rm -f "$output_file"
    echo "ERROR: Test speech duration could not be measured; no speech was queued." | tee -a "$LOG" >&2
    printf '%s\n' 'SLS_TTS_ERROR:Test speech duration could not be measured; no speech was queued.'
    return 1
  fi
  if awk "BEGIN { exit !($duration > ${PIPER_MAX_SECONDS:-30}) }"; then
    rm -f "$output_file"
    echo "ERROR: Test speech lasts ${duration} seconds; the configured maximum is ${PIPER_MAX_SECONDS:-30} seconds. Increase the speech limit to fit the complete message; no speech was queued." | tee -a "$LOG" >&2
    printf '%s\n' "SLS_TTS_ERROR:Test speech lasts ${duration} seconds; the configured maximum is ${PIPER_MAX_SECONDS:-30} seconds. Increase the speech limit to fit the complete message; no speech was queued."
    return 1
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
    # Shared maintenance owns media retention and honors active paging leases.
    :
  fi
}

trigger_visual_test() {
	local channel="${1:-all}"
	local event
	local severity
	local description
	local test_id
	local targets
	local desktop_targets
	local -a command

  refresh_api_test_permissions || return 1

  if [ ! -x "$VISUAL_PUSH_SCRIPT" ]; then
    echo "$(date): Visual test skipped — visual notification worker is not executable" >> "$LOG"
    return 1
  fi

  event="Manual NWS Test"
  severity="Test"
  if [ -z "$VISUAL_TEST_ID" ]; then
    VISUAL_TEST_ID="pbx-gui-test-$(date +%Y%m%d%H%M%S)-$$"
  fi
  test_id="$VISUAL_TEST_ID"
  description="PBX TEST - Simulated ${event}. This visual alert was triggered from the FreePBX Mass Notifications testing page."
  targets="$(get_nws_recipient_targets)"
  desktop_targets="$(get_nws_desktop_targets)"
  if [ "$channel" = "phone" ]; then
    desktop_targets=""
  elif [ "$channel" = "desktop" ]; then
    targets=""
  elif [ "$channel" != "all" ]; then
    echo "$(date): Visual test skipped — invalid requested channel" >> "$LOG"
    return 1
  fi
  if [ -z "$targets" ] && [ -z "$desktop_targets" ]; then
    echo "$(date): Visual test skipped — no phone or desktop recipients configured" >> "$LOG"
    return 1
  fi

  command=(
    /usr/bin/timeout 45 /usr/bin/python3 "$VISUAL_PUSH_SCRIPT"
    --event "$event" \
    --severity "$severity" \
    --area "${NWS_ZONE:-Configured NWS zone}" \
    --description "$description" \
    --test-id "$test_id" \
    --no-retry
  )
  if [ -n "$targets" ]; then
    command+=(--targets "$targets" --require-all-targets)
  else
    command+=(--api-only)
  fi
  if [ -n "$desktop_targets" ]; then
    command+=(--desktop-targets "$desktop_targets")
  else
    command+=(--no-api)
  fi

  echo "$(date): Submitting manual Weather visual to requested phone/desktop channels — Event: $event" >> "$LOG"
  if ! "${command[@]}" >> "$LOG" 2>&1; then
    echo "$(date): ERROR — Manual Weather visual channel submission failed" >> "$LOG"
    return 1
  fi
  if [ "$channel" = "desktop" ]; then
    /usr/bin/python3 -c 'import json,sys; print("SLS_TEST_DESKTOP_PUBLICATION " + json.dumps({"event_id":sys.argv[1],"targets":sys.argv[2].split(",")}))' "$test_id" "$desktop_targets"
  fi
  return 0
}

get_nws_recipient_targets() {
  local IFS=,
  printf '%s\n' "${NWS_ALERT_RECIPIENTS[*]}"
}

get_nws_desktop_targets() {
  local IFS=,
  printf '%s\n' "${NWS_DESKTOP_CLIENTS[*]}"
}

audio_page_hold_seconds() {
  local sound_sequence="$1"
  local sound_file
  local duration

  [[ "$sound_sequence" =~ ^[A-Za-z0-9_/-]+$ ]] || return 1
  sound_file="${ASTERISK_SOUNDS_DIR}/${sound_sequence}.wav"
  [ -r "$sound_file" ] || return 1
  duration="$(LC_ALL=C /usr/bin/soxi -D "$sound_file" 2>/dev/null)" || return 1
  # Keep Page's originating Local channel through the complete WAV and a
  # bounded teardown margin without adding its separate participant timeout.
  LC_ALL=C awk -v duration="$duration" 'BEGIN {
    if (duration <= 0 || duration > 1767) exit 1
    rounded = int(duration)
    if (duration > rounded) rounded++
    print rounded + 2
  }'
}

queue_test_audio_to_recipients() {
  local sound_sequence="$1"
  local raw_recipient
  local recipient
  local callfile
  local queued=0
  local page_hold_seconds
  local call_wait_seconds
  local -a valid_recipients=()
  local -A seen_recipients=()

  TEST_CALL_QUEUE_FAILURES=0
  refresh_api_test_permissions || return 1

  if [ "${#NWS_ALERT_RECIPIENTS[@]}" -eq 0 ]; then
    echo "$(date): ERROR — No NWS alert recipient extensions configured" >> "$LOG"
    report_fault "delivery" "No NWS alert recipient extensions configured"
    return 1
  fi

  for raw_recipient in "${NWS_ALERT_RECIPIENTS[@]}"; do
    if [[ ! "$raw_recipient" =~ ^[0-9]{1,20}$ ]]; then
      echo "$(date): ERROR — Invalid manual Weather audio recipient was not queued" >> "$LOG"
      TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + 1))
      continue
    fi
    if [ -z "${seen_recipients[$raw_recipient]:-}" ]; then
      valid_recipients+=("$raw_recipient")
      seen_recipients["$raw_recipient"]=1
    fi
  done
  if [ "${#valid_recipients[@]}" -eq 0 ]; then
    report_fault "delivery" "No valid manual Weather audio recipients were selected; no test audio was submitted"
    return 1
  fi

  if ! page_hold_seconds="$(audio_page_hold_seconds "$sound_sequence")"; then
    echo "$(date): ERROR — Unable to measure the complete test audio sequence" >> "$LOG"
    report_fault "delivery" "Unable to measure the complete manual test audio sequence"
    return 1
  fi
  call_wait_seconds=$((page_hold_seconds + 30))

  local reservation_recipients
  reservation_recipients="$(IFS=,; printf '%s' "${valid_recipients[*]}")"
  # Manual tests share recipient exclusion and media leases with live alerts.
  # Bound this wait separately so a busy page does not prevent the remaining
  # requested visual channels from being attempted by the manual test worker.
  if ! /usr/bin/timeout --signal=TERM --kill-after=2 30 /usr/bin/python3 "$AUDIO_QUEUE_HELPER" \
      --recipients "$reservation_recipients" --duration "$page_hold_seconds" --sound "$sound_sequence" --priority normal >> "$LOG" 2>&1; then
    TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + ${#valid_recipients[@]}))
    echo "$(date): ERROR — Shared paging queue could not reserve the manual Weather recipients; no test audio was submitted" >> "$LOG"
    report_fault "delivery" "Shared paging queue could not reserve the manual Weather recipients within the test wait limit; no test audio was submitted"
    return 1
  fi
  local admission_lines phone_token
  local -a admitted_recipients=()
  if ! admission_lines="$(/usr/bin/timeout --signal=TERM --kill-after=2 65 /usr/bin/python3 -I /usr/local/bin/sls_mass_notify/sls_phone_admission.py \
      --recipients "$reservation_recipients" --service manual --correlation "${SLS_TEST_DELIVERY_ID:-}" --wait 20 --format lines)"; then
    printf '%s\n' "$admission_lines" >> "$LOG"
    report_fault "delivery" "Phone device capacity or registered contacts could not be admitted; no new audio was submitted"
    TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + ${#valid_recipients[@]}))
    return 1
  fi
  mapfile -t admitted_recipients <<< "$admission_lines"
  phone_token="${admitted_recipients[0]:-}"
  if [[ ! "$phone_token" =~ ^[a-f0-9]{32}$ ]]; then
    report_fault "delivery" "Phone admission returned an invalid ticket; no new audio was submitted"
    return 1
  fi
  admitted_recipients=("${admitted_recipients[@]:1}")
  TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + ${#valid_recipients[@]} - ${#admitted_recipients[@]}))
  # Admission can wait for capacity. Recheck once more before any call file is
  # written and intersect the admitted set; never replace it with new targets.
  refresh_api_test_permissions || return 1
  for recipient in "${admitted_recipients[@]}"; do
    if [ -n "${SLS_API_TEST_CONTEXT:-}" ] && [[ ! ",$(IFS=,; printf '%s' "${NWS_ALERT_RECIPIENTS[*]}")," == *",${recipient},"* ]]; then
      TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + 1))
      continue
    fi
    if ! callfile="$(mktemp "$SPOOL_TMP/sls_test_XXXXXX.call")"; then
      echo "$(date): ERROR — Unable to create the manual Weather call file for extension $recipient" >> "$LOG"
      TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + 1))
      continue
    fi
    if ! cat > "$callfile" << CALL
Channel: Local/${recipient}@${SLS_AUDIO_CONTEXT}/n
Account: slsphone_${phone_token}
Setvar: __SLS_PHONE_TOKEN=${phone_token}
Setvar: __SLS_PHONE_RECIPIENT=${recipient}
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
    then
      echo "$(date): ERROR — Unable to write the manual Weather call file for extension $recipient" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + 1))
      continue
    fi
    chown asterisk:asterisk "$callfile" 2>/dev/null || true
    if ! chmod 0640 "$callfile"; then
      echo "$(date): ERROR — Unable to secure the manual Weather call file for extension $recipient" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + 1))
      continue
    fi
    if ! mv "$callfile" "$SPOOL/"; then
      echo "$(date): ERROR — Unable to move test call file for $recipient into $SPOOL" >> "$LOG"
      rm -f "$callfile" 2>/dev/null || true
      TEST_CALL_QUEUE_FAILURES=$((TEST_CALL_QUEUE_FAILURES + 1))
      continue
    fi
    callfile="$SPOOL/$(basename "$callfile")"
    TEST_CALL_QUEUE_PATHS+=("$callfile")
    TEST_CALL_RECIPIENTS["$callfile"]="$recipient"
    queued=$((queued + 1))
  done

  if [ "$queued" -eq 0 ]; then
    return 1
  fi

  echo "$(date): Test call files queued to $queued recipient(s) — $sound_sequence" >> "$LOG"
  [ "$TEST_CALL_QUEUE_FAILURES" -eq 0 ]
}

wait_for_test_call_pickup() {
  local timeout_seconds="$TEST_SPOOL_PICKUP_TIMEOUT_SECONDS"
  local deadline
  local queue_file
  local recipient
  local channel_inventory
  local pending
  local failed=0

  [[ "$timeout_seconds" =~ ^[0-9]+$ ]] || timeout_seconds=10
  [ "$timeout_seconds" -ge 1 ] 2>/dev/null || timeout_seconds=10
  [ "$timeout_seconds" -le 30 ] 2>/dev/null || timeout_seconds=30
  deadline=$((SECONDS + timeout_seconds))
  [ "${#TEST_CALL_QUEUE_PATHS[@]}" -gt 0 ] || return 1
  while [ "$SECONDS" -lt "$deadline" ]; do
    pending=0
    channel_inventory=""
    if [ -x "$ASTERISK_CLI_BIN" ]; then
      channel_inventory="$("$ASTERISK_CLI_BIN" -rx 'core show channels concise' 2>/dev/null || true)"
    fi
    for queue_file in "${TEST_CALL_QUEUE_PATHS[@]}"; do
      [ "${TEST_CALL_ACCEPTED[$queue_file]:-0}" = "1" ] && continue
      recipient="${TEST_CALL_RECIPIENTS[$queue_file]:-}"
      if [ ! -e "$queue_file" ]; then
        TEST_CALL_ACCEPTED["$queue_file"]=1
      elif [ -n "$recipient" ] && printf '%s\n' "$channel_inventory" \
        | grep -Fq "Local/${recipient}@${SLS_AUDIO_CONTEXT}-"; then
        # Asterisk keeps an active call file in the outgoing spool until the
        # Local channel finishes.  Seeing that channel is positive pickup;
        # requiring the file to disappear first falsely fails longer pages.
        TEST_CALL_ACCEPTED["$queue_file"]=1
      else
        pending=$((pending + 1))
      fi
    done
    [ "$pending" -eq 0 ] && break
    sleep 1
  done

  for queue_file in "${TEST_CALL_QUEUE_PATHS[@]}"; do
    if [ "${TEST_CALL_ACCEPTED[$queue_file]:-0}" != "1" ] && [ -e "$queue_file" ]; then
      echo "$(date): ERROR — Asterisk did not pick up the manual Weather audio job for extension ${TEST_CALL_RECIPIENTS[$queue_file]:-unknown} within ${timeout_seconds} seconds" >> "$LOG"
      rm -f "$queue_file" 2>/dev/null || true
      failed=1
    fi
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
  NWS_DESKTOP_CLIENTS=()
  while IFS= read -r -d '' key && IFS= read -r -d '' value; do
    case "$key" in
      NWS_ALERT_RECIPIENT) NWS_ALERT_RECIPIENTS+=("$value") ;;
      NWS_DESKTOP_CLIENT) NWS_DESKTOP_CLIENTS+=("$value") ;;
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
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"fault","last_test_stage":"configuration","last_test_message":"Central configuration is invalid or unavailable."}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")")"
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
  NWS_DESKTOP_CLIENTS=()
  if [ -n "$NWS_DESKTOP_CLIENTS_OVERRIDE" ]; then
    IFS=',' read -r -a NWS_DESKTOP_CLIENTS <<< "$NWS_DESKTOP_CLIENTS_OVERRIDE"
  fi
fi
DELIVERY_TARGETS="$(get_nws_recipient_targets)"
DESKTOP_DELIVERY_TARGETS="$(get_nws_desktop_targets)"
refresh_api_test_permissions || exit 1

if [ "${#NWS_ALERT_RECIPIENTS[@]}" -eq 0 ] && [ "${#NWS_DESKTOP_CLIENTS[@]}" -eq 0 ]; then
  echo "ERROR: The selected Weather zones do not have a phone or desktop channel that can be tested. Email destinations are intentionally skipped during manual tests."
  report_fault "configuration" "Selected Weather zones have no testable phone or desktop recipients"
  exit 1
fi

if [ "${NWS_ALERTS_ENABLED:-1}" != "1" ]; then
  echo "$(date): NWS alerts are disabled in settings; manual test skipped" >> "$LOG"
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"skipped","last_test_stage":"","last_test_message":"Manual test skipped because alerts are disabled."}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")")"
  exit 0
fi

prune_tts_cache

COOLDOWN_RESULT=""
COOLDOWN_EXIT=0
COOLDOWN_RESULT="$(claim_test_cooldown)" || COOLDOWN_EXIT=$?
if [ "$COOLDOWN_EXIT" -eq 3 ]; then
  REMAINING="${COOLDOWN_RESULT#COOLDOWN }"
  case "$REMAINING" in
    ''|*[!0-9]*) REMAINING="$COOLDOWN_SECONDS" ;;
  esac
  echo "$(date): Manual test blocked by cooldown — ${REMAINING}s remaining" >> "$LOG"
  update_status "$(printf '{"last_test_at":%s,"last_test_status":"cooldown","last_test_stage":"cooldown","last_test_message":%s}' \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$(timestamp_now)")" \
    "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "Manual test blocked by cooldown (${REMAINING}s remaining).")")"
  echo "ERROR: Manual testing is on cooldown (${REMAINING}s remaining)."
  exit 75
fi
if [ "$COOLDOWN_EXIT" -ne 0 ] || [ "$COOLDOWN_RESULT" != "CLAIMED" ]; then
  echo "$(date): ERROR - Manual test cooldown state could not be secured" >> "$LOG"
  report_fault "cooldown" "Manual test cooldown state could not be secured"
  echo "ERROR: Manual test cooldown state could not be secured."
  exit 1
fi

echo "$(date): Manual Weather channel test triggered" >> "$LOG"

AUDIO_SEQUENCE=""
PHONE_AUDIO_OK=1
PHONE_AUDIO_STARTED=0
DELIVERY_FAILURES=()

# Desktop delivery is a durable journal publication and does not depend on a
# phone, Asterisk call-file pickup, or whether the desktop is presently awake.
# Publish it before synchronous TTS preparation so a live SSE client receives
# the test without waiting for phone work.
if [ "${#NWS_DESKTOP_CLIENTS[@]}" -gt 0 ]; then
  if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
    echo "$(date): Dry run — would publish the manual Weather visual to the requested desktop channels" >> "$LOG"
  elif trigger_visual_test desktop; then
    echo "OK: Targeted Desktop journal publication completed."
  else
    DELIVERY_FAILURES+=("targeted Desktop journal publication failed")
    echo "ERROR: Targeted Desktop journal publication failed."
  fi
fi

if [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ]; then
  TTS_FILE="$(generate_test_tts_audio)"
  if [ -z "$TTS_FILE" ] || [[ "$TTS_FILE" == SLS_TTS_ERROR:* ]]; then
    PHONE_AUDIO_OK=0
    TTS_ERROR="Piper TTS test audio was not generated"
    [[ "$TTS_FILE" != SLS_TTS_ERROR:* ]] || TTS_ERROR="${TTS_FILE#SLS_TTS_ERROR:}"
    TTS_FILE=""
    DELIVERY_FAILURES+=("$TTS_ERROR")
    echo "ERROR: $TTS_ERROR"
  else
    AUDIO_SEQUENCE="$(build_audio_sequence "$TTS_FILE")"
    if [ -z "$AUDIO_SEQUENCE" ]; then
      PHONE_AUDIO_OK=0
      DELIVERY_FAILURES+=("Piper TTS test audio sequence was not generated")
      echo "ERROR: Piper TTS test audio sequence was not generated."
    else
      TEST_AUDIO_LABEL="Piper TTS"
    fi
  fi
fi

# Build call files using the same direct audio path as live NWS alerts.
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  if [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ]; then
    echo "$(date): Dry run — would queue test call files using $AUDIO_SEQUENCE to recipients: ${NWS_ALERT_RECIPIENTS[*]}" >> "$LOG"
  fi
elif [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ] && [ "$PHONE_AUDIO_OK" = "1" ]; then
  QUEUE_RESULT=0
  queue_test_audio_to_recipients "$AUDIO_SEQUENCE" || QUEUE_RESULT=$?
  if [ "$QUEUE_RESULT" -ne 0 ]; then
    PHONE_AUDIO_OK=0
    DELIVERY_FAILURES+=("one or more Weather audio page jobs could not be queued")
    echo "ERROR: One or more requested audio page jobs could not be queued."
  fi
  if [ "${#TEST_CALL_QUEUE_PATHS[@]}" -gt 0 ]; then
    if wait_for_test_call_pickup; then
      PHONE_AUDIO_STARTED=1
    else
      PHONE_AUDIO_OK=0
      DELIVERY_FAILURES+=("Asterisk did not pick up one or more Weather audio page jobs")
      echo "ERROR: Asterisk did not pick up one or more requested audio page jobs. Review Asterisk service and outgoing-spool permissions."
    fi
  fi
fi

# Phone SIP NOTIFY remains page-first. Desktop publication above is independent
# and must never be held behind an offline phone or a stuck outgoing job.
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  [ "${#NWS_ALERT_RECIPIENTS[@]}" -eq 0 ] \
    || echo "$(date): Dry run — would publish the manual Weather visual to the requested phone channels" >> "$LOG"
elif [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ]; then
  if [ "$PHONE_AUDIO_STARTED" = "1" ]; then
    sleep 2
  fi
  if ! trigger_visual_test phone; then
    DELIVERY_FAILURES+=("phone SIP NOTIFY submission failed")
    echo "ERROR: Phone SIP NOTIFY submission failed for at least one requested endpoint."
  fi
fi

if [ "$API_TEST_REMOVED_RECIPIENTS" -gt 0 ]; then
  DELIVERY_FAILURES+=("API permissions or recipient identities changed; removed recipients were not submitted")
fi
if [ "${#DELIVERY_FAILURES[@]}" -eq 0 ]; then
  echo "$(date): Requested manual Weather channels accepted the local submission — phones=${#NWS_ALERT_RECIPIENTS[@]} desktops=${#NWS_DESKTOP_CLIENTS[@]}" >> "$LOG"
else
  printf -v TEST_FAILURE_MESSAGE '%s; ' "${DELIVERY_FAILURES[@]}"
  TEST_FAILURE_MESSAGE="${TEST_FAILURE_MESSAGE%; }"
  echo "$(date): ERROR — Manual Weather test completed with channel failures: $TEST_FAILURE_MESSAGE" >> "$LOG"
fi
DELIVERY_TS="$(timestamp_now)"
TEST_DELIVERY_STATUS="submitted"
TEST_DELIVERY_STAGE=""
if [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ] && [ "${#NWS_DESKTOP_CLIENTS[@]}" -gt 0 ]; then
  TEST_DELIVERY_MESSAGE="Asterisk picked up the manual audio page jobs and accepted SIP NOTIFY submission; targeted desktop publication completed; endpoint display and handset acceptance are not confirmed"
elif [ "${#NWS_ALERT_RECIPIENTS[@]}" -gt 0 ]; then
  TEST_DELIVERY_MESSAGE="Asterisk picked up the manual audio page jobs and accepted SIP NOTIFY submission; handset acceptance is not confirmed"
else
  TEST_DELIVERY_MESSAGE="Targeted desktop publication completed; desktop application display is not confirmed"
fi
if [ "$NWS_ALERTS_DRY_RUN" = "1" ]; then
  TEST_DELIVERY_STATUS="dry_run"
  TEST_DELIVERY_MESSAGE="Dry run completed for manual Piper TTS test"
elif [ "${#DELIVERY_FAILURES[@]}" -gt 0 ]; then
  TEST_DELIVERY_STATUS="partial_failure"
  TEST_DELIVERY_STAGE="delivery"
  TEST_DELIVERY_MESSAGE="Manual Weather test completed with channel failures: ${TEST_FAILURE_MESSAGE}. Every requested local channel was attempted; successful submissions were not replayed"
  report_fault "delivery" "$TEST_DELIVERY_MESSAGE"
fi
update_status "$(printf '{"last_delivery_at":%s,"last_delivery_status":%s,"last_delivery_source":"test","last_delivery_event":"Manual NWS Test","last_delivery_audio":%s,"last_delivery_message":%s,"last_delivery_page_group":%s,"last_delivery_desktop_clients":%s}' \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_STATUS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_AUDIO_LABEL")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_MESSAGE")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TARGETS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DESKTOP_DELIVERY_TARGETS")")"
update_status "$(printf '{"last_test_at":%s,"last_test_status":%s,"last_test_stage":%s,"last_test_message":%s,"last_test_audio":%s,"last_test_phone_count":%d,"last_test_desktop_count":%d}' \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$DELIVERY_TS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_STATUS")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_STAGE")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_DELIVERY_MESSAGE")" \
  "$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$TEST_AUDIO_LABEL")" \
  "${#NWS_ALERT_RECIPIENTS[@]}" \
  "${#NWS_DESKTOP_CLIENTS[@]}")"
CURRENT_TIME="$(date)"
MAIL_SUBJECT="$(render_template "$TEST_EMAIL_SUBJECT" \
  "event=Manual NWS Test" \
  "severity=Test" \
  "message_type=Test" \
  "audio=$TEST_AUDIO_LABEL" \
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
  "audio=$TEST_AUDIO_LABEL" \
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
  if [ "${#DELIVERY_FAILURES[@]}" -gt 0 ]; then
    echo "ERROR: Manual Weather test completed with channel failures: ${TEST_FAILURE_MESSAGE}. Every requested local channel was attempted; successful submissions were not replayed."
    exit 1
  fi
  echo "Weather test local submission completed for ${#NWS_ALERT_RECIPIENTS[@]} phone recipient(s) and ${#NWS_DESKTOP_CLIENTS[@]} desktop recipient(s). Endpoint display and handset acceptance are not confirmed. Email and webhooks were not sent."
fi
