#!/usr/bin/env bash
# Scheduled observations and queued delivery are intentionally separate workers.
set -uo pipefail
RUNTIME_DIR="${RUNTIME_DIR:-/usr/local/bin/sls_mass_notify}"
CONFIG_FILE="${CONFIG_FILE:-/var/lib/asterisk/SLS_Mass_Notifications_Plugin/mass-notifications.config}"
case "$#" in
  0) exec /usr/bin/python3 "$RUNTIME_DIR/sls_weather_queue.py" cycle ;;
  1)
    case "$1" in
      -h|--help) printf '%s\n' 'Usage: sls_mass_notify_weather_poll.sh' 'Poll Weather.gov and Lightning, then start the separate delivery worker.'; exit 0 ;;
      --groups-json) ;;
      *) printf 'Unknown argument: %s\n' "$1" >&2; printf '%s\n' 'Usage: sls_mass_notify_weather_poll.sh' >&2; exit 2 ;;
    esac ;;
  *) printf '%s\n' 'Unexpected positional arguments.' >&2; exit 2 ;;
esac
/usr/bin/python3 - "$CONFIG_FILE" <<'PY'
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
