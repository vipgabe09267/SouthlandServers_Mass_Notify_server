#!/usr/bin/env python3
"""Exercise the manual Weather test's channel-aware delivery contract."""

import json
import os
import pathlib
import subprocess
import tempfile
import time


ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_test.sh"


def fail(message):
    raise AssertionError(message)


def write_executable(path, source):
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def run_case(
    phone_targets,
    desktop_targets,
    *,
    visual_exit=0,
    pick_up=True,
    active_while_queued=False,
    preseed_cooldown_seconds_ago=None,
):
    with tempfile.TemporaryDirectory(prefix="sls-weather-test-contract-") as directory:
        scratch = pathlib.Path(directory)
        sounds = scratch / "sounds"
        tts = sounds / "SLS_Mass_Notifications_Plugin/tts"
        tones = sounds / "SLS_Mass_Notifications_Plugin/tones"
        spool = scratch / "outgoing"
        spool_tmp = scratch / "tmp"
        for path in (tts, tones, spool, spool_tmp):
            path.mkdir(parents=True, exist_ok=True)

        config = scratch / "mass-notifications.config"
        config.write_text("{}\n", encoding="utf-8")
        model = scratch / "voice.onnx"
        model.write_bytes(b"test-model")
        piper_marker = scratch / "piper-ran"
        visual_args = scratch / "visual-args.json"

        piper = scratch / "piper"
        write_executable(
            piper,
            """#!/usr/bin/python3
import os
import pathlib
import sys
import wave

args = sys.argv[1:]
output = pathlib.Path(args[args.index("--output-file") + 1])
with wave.open(str(output), "wb") as handle:
    handle.setnchannels(1)
    handle.setsampwidth(2)
    handle.setframerate(8000)
    handle.writeframes(b"\\0\\0" * 800)
pathlib.Path(os.environ["PIPER_MARKER"]).write_text("ran\\n", encoding="utf-8")
""",
        )

        config_loader = scratch / "config-loader.py"
        write_executable(
            config_loader,
            f"""#!/usr/bin/python3
import sys

pairs = (
    ("NWS_ALERTS_ENABLED", "1"),
    ("NWS_ZONE", "TXC491"),
    ("SLS_OPENING_TONE", ""),
    ("SLS_CLOSING_TONE", ""),
    ("PIPER_BIN", {str(piper)!r}),
    ("PIPER_NWS_VOICE", {str(model)!r}),
    ("PIPER_NWS_VOLUME", "0.25"),
    ("PIPER_MAX_SECONDS", "30"),
    ("LOG_RETENTION_DAYS", "90"),
    ("TEST_EMAIL_SUBJECT", "Manual Weather Test"),
    ("TEST_EMAIL_BODY", "Manual Weather Test"),
)
for key, value in pairs:
    sys.stdout.buffer.write(key.encode() + b"\\0" + value.encode() + b"\\0")
""",
        )

        visual = scratch / "visual.py"
        write_executable(
            visual,
            """#!/usr/bin/python3
import json
import os
import pathlib
import sys

with pathlib.Path(os.environ["VISUAL_ARGS_FILE"]).open("a", encoding="utf-8") as handle:
    handle.write(json.dumps(sys.argv[1:]) + "\\n")
raise SystemExit(int(os.environ.get("VISUAL_EXIT", "0")))
""",
        )

        asterisk_cli = scratch / "asterisk"
        write_executable(
            asterisk_cli,
            """#!/usr/bin/python3
import os

print(os.environ.get("MOCK_ASTERISK_CHANNELS", ""))
""",
        )

        cooldown_file = scratch / "cooldown.ts"
        if preseed_cooldown_seconds_ago is not None:
            cooldown_file.write_text(
                str(int(time.time()) - int(preseed_cooldown_seconds_ago)) + "\n",
                encoding="ascii",
            )

        env = os.environ.copy()
        env.update(
            {
                "ASTERISK_SOUNDS_DIR": str(sounds),
                "ASTERISK_CLI_BIN": str(asterisk_cli),
                "CONFIG_JSON_FILE": str(config),
                "CONFIG_LOADER": str(config_loader),
                "COOLDOWN_FILE": str(cooldown_file),
                "EVENTS_LOG": str(scratch / "events.jsonl"),
                "LOG": str(scratch / "worker.log"),
                "MOCK_ASTERISK_CHANNELS": (
                    "Local/1000@sls-alert-audio-00000001;2!"
                    "sls-alert-audio!1000!17!Up"
                    if active_while_queued
                    else ""
                ),
                "NWS_DESKTOP_CLIENTS_OVERRIDE": ",".join(desktop_targets),
                "NWS_RECIPIENTS_OVERRIDE": ",".join(phone_targets),
                "NWS_ZONE_OVERRIDE": "TXC491",
                "PIPER_MARKER": str(piper_marker),
                "SLS_TONES_DIR": str(tones),
                "SLS_TTS_DIR": str(tts),
                "SPOOL": str(spool),
                "SPOOL_TMP": str(spool_tmp),
                "STATUS_FILE": str(scratch / "status.json"),
                "TEST_SPOOL_PICKUP_TIMEOUT_SECONDS": "1",
                "VISUAL_ARGS_FILE": str(visual_args),
                "VISUAL_EXIT": str(visual_exit),
                "VISUAL_PUSH_SCRIPT": str(visual),
            }
        )
        process = subprocess.Popen(
            ["bash", str(WORKER), "GUI", "Contract Test"],
            cwd=ROOT,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        captured_calls = []
        captured_paths = set()
        deadline = time.monotonic() + 20
        while process.poll() is None and time.monotonic() < deadline:
            for call_file in spool.glob("sls_test_*.call"):
                if str(call_file) not in captured_paths:
                    captured_calls.append(call_file.read_text(encoding="utf-8"))
                    captured_paths.add(str(call_file))
                if pick_up:
                    call_file.unlink()
            time.sleep(0.02)
        if process.poll() is None:
            process.kill()
            fail("manual Weather test contract case timed out")
        stdout, stderr = process.communicate(timeout=2)
        status = json.loads((scratch / "status.json").read_text(encoding="utf-8"))
        calls = (
            [json.loads(line) for line in visual_args.read_text(encoding="utf-8").splitlines()]
            if visual_args.exists()
            else []
        )
        return {
            "returncode": process.returncode,
            "stdout": stdout,
            "stderr": stderr,
            "status": status,
            "visual_args": [argument for call in calls for argument in call],
            "visual_calls": calls,
            "piper_ran": piper_marker.exists(),
            "calls": captured_calls,
            "cooldown_lock_exists": pathlib.Path(str(cooldown_file) + ".lock").exists(),
        }


desktop = run_case([], ["desk_ops"])
if desktop["returncode"] != 0:
    fail(f"desktop-only Weather test failed: {desktop}")
if desktop["piper_ran"] or desktop["calls"]:
    fail("desktop-only Weather test incorrectly generated or queued phone audio")
if "--api-only" not in desktop["visual_args"] or "--targets" in desktop["visual_args"]:
    fail("desktop-only Weather test did not use API-only visual publication")
if desktop["visual_args"][-2:] != ["--desktop-targets", "desk_ops"]:
    fail("desktop-only Weather test did not preserve its explicit desktop target")
if desktop["status"].get("last_test_desktop_count") != 1:
    fail("desktop-only Weather test status did not record its channel count")
if desktop["status"].get("last_test_stage") != "":
    fail("successful Weather test retained a stale failure stage")

phone = run_case(["1000"], [])
if phone["returncode"] != 0:
    fail(f"phone-only Weather test failed: {phone}")
if not phone["piper_ran"] or len(phone["calls"]) != 1:
    fail("phone-only Weather test did not generate exactly one audio page job")
if "--targets" not in phone["visual_args"] or "--no-api" not in phone["visual_args"]:
    fail("phone-only Weather test did not isolate SIP NOTIFY from desktop publication")
if "--require-all-targets" not in phone["visual_args"]:
    fail("phone-only Weather test did not require every selected phone endpoint")
if "Archive: yes" in phone["calls"][0] or "Data: 1\n" in phone["calls"][0]:
    fail("phone test retained the false archive gate or a one-second Page origin")
if "Channel: Local/1000@sls-alert-audio" not in phone["calls"][0]:
    fail("phone test did not queue its requested extension")

mixed = run_case(["1000"], ["desk_ops"])
if mixed["returncode"] != 0:
    fail(f"mixed-channel Weather test failed: {mixed}")
if len(mixed["visual_calls"]) != 2:
    fail("mixed-channel Weather test did not isolate desktop and phone publication")
desktop_call, phone_call = mixed["visual_calls"]
for expected in ("--api-only", "--desktop-targets", "desk_ops"):
    if expected not in desktop_call:
        fail(f"mixed-channel Weather desktop publication omitted {expected}")
if "--targets" in desktop_call or "--no-api" in desktop_call:
    fail("mixed-channel Weather desktop publication depends on phone delivery")
for expected in ("--targets", "1000", "--require-all-targets", "--no-api"):
    if expected not in phone_call:
        fail(f"mixed-channel Weather phone publication omitted {expected}")
if "--desktop-targets" in phone_call or "--api-only" in phone_call:
    fail("mixed-channel Weather phone publication can duplicate the desktop event")
desktop_test_id = desktop_call[desktop_call.index("--test-id") + 1]
phone_test_id = phone_call[phone_call.index("--test-id") + 1]
if desktop_test_id != phone_test_id:
    fail("split phone/Desktop delivery did not retain one logical test identifier")

# Asterisk retains a call file in the outgoing spool while a longer Local
# channel is active.  That is successful pickup, not a stuck spool job.
active_call = run_case(
    ["1000"],
    ["desk_ops"],
    pick_up=False,
    active_while_queued=True,
)
if active_call["returncode"] != 0:
    fail(f"active Asterisk page was mistaken for an unconsumed job: {active_call}")
if len(active_call["calls"]) != 1 or len(active_call["visual_calls"]) != 2:
    fail("active Asterisk page did not continue to SIP NOTIFY/desktop publication")

visual_failure = run_case([], ["desk_ops"], visual_exit=1)
if visual_failure["returncode"] == 0:
    fail("desktop publication failure was incorrectly reported as success")
if visual_failure["status"].get("last_test_stage") != "delivery":
    fail("desktop publication failure did not preserve its failure stage")

pickup_failure = run_case(["1000"], [], pick_up=False)
if pickup_failure["returncode"] == 0:
    fail("unconsumed Asterisk audio job was incorrectly reported as success")
if pickup_failure["status"].get("last_test_stage") != "delivery":
    fail("Asterisk audio pickup failure did not preserve its failure stage")
if len(pickup_failure["visual_calls"]) != 1 or "--targets" not in pickup_failure["visual_calls"][0]:
    fail("phone SIP NOTIFY was not attempted after an audio queue pickup failure")

stuck_phone_with_desktop = run_case(
    ["1000"], ["desk_ops"], pick_up=False
)
if stuck_phone_with_desktop["returncode"] == 0:
    fail("stuck phone audio was incorrectly reported as full success")
if len(stuck_phone_with_desktop["visual_calls"]) != 2:
    fail("stuck phone audio prevented a requested visual channel from being attempted")
desktop_call, phone_call = stuck_phone_with_desktop["visual_calls"]
if "--api-only" not in desktop_call or "desk_ops" not in desktop_call:
    fail("stuck phone audio prevented independent targeted Desktop publication")
if "--targets" not in phone_call or "--require-all-targets" not in phone_call:
    fail("stuck phone audio prevented strict phone SIP NOTIFY validation")
if "OK: Targeted Desktop journal publication completed." not in stuck_phone_with_desktop["stdout"]:
    fail("partial failure output did not preserve successful Desktop publication")

partial_queue = run_case(["1000", "invalid"], ["desk_ops"])
if partial_queue["returncode"] == 0:
    fail("a partially queued multi-extension page was incorrectly reported as success")
if len(partial_queue["calls"]) != 1:
    fail("a bad recipient prevented a valid recipient's audio job from being queued")
if len(partial_queue["visual_calls"]) != 2:
    fail("a partial audio queue failure prevented phone or Desktop visual submission")
if "one or more Weather audio page jobs could not be queued" not in partial_queue["stdout"]:
    fail("a partial multi-extension queue failure was not reported accurately")

no_channels = run_case([], [])
if no_channels["returncode"] == 0:
    fail("an email-only/no-channel manual test was incorrectly reported as success")
if no_channels["status"].get("last_test_stage") != "configuration":
    fail("no-channel test did not report a configuration-stage failure")

cooldown = run_case([], ["desk_ops"], preseed_cooldown_seconds_ago=0)
if cooldown["returncode"] != 75:
    fail(f"worker-level cooldown did not fail visibly: {cooldown}")
if cooldown["status"].get("last_test_status") != "cooldown":
    fail("worker-level cooldown did not preserve its status")
if cooldown["visual_args"] or cooldown["piper_ran"] or cooldown["calls"]:
    fail("a blocked cooldown request started a delivery channel")
if not cooldown["cooldown_lock_exists"]:
    fail("manual Weather cooldown did not create its atomic lock")

class_source = (
    ROOT / "slsmassnotifyserver/Slsmassnotifyserver.class.php"
).read_text(encoding="utf-8")
trigger_source = class_source.split(
    "\tpublic function triggerTest", 1
)[1].split("\n\tpublic function verifyLightningConnection", 1)[0]
for marker in (
    "NWS_DESKTOP_CLIENTS_OVERRIDE",
    "unavailable or disabled desktop clients",
    "Asterisk picked up the audio jobs and accepted SIP NOTIFY",
    "per-zone email",
    "unknown or invalid zone selection",
    "Successful channel submissions are not replayed",
):
    if marker not in trigger_source:
        fail(f"Weather test controller contract is missing: {marker}")
for obsolete in (
    "do not have any recipient extensions",
    "completes the audio page job",
    "page-job completion",
):
    if obsolete in trigger_source:
        fail(f"Weather test controller retained its false phone-only gate: {obsolete}")

worker_source = WORKER.read_text(encoding="utf-8")
for marker in ("claim_test_cooldown", "fcntl.flock", 'O_NOFOLLOW', "exit 75"):
    if marker not in worker_source:
        fail(f"manual Weather cooldown contract is missing: {marker}")

dispatch_source = worker_source.split(
    'echo "$(date): Manual Weather channel test triggered"', 1
)[1]
desktop_position = dispatch_source.find("trigger_visual_test desktop")
tts_position = dispatch_source.find('TTS_FILE="$(generate_test_tts_audio)"')
phone_position = dispatch_source.find("trigger_visual_test phone")
if min(desktop_position, tts_position, phone_position) < 0 or not (
    desktop_position < tts_position < phone_position
):
    fail("targeted Desktop publication is still held behind TTS or phone work")
for forbidden in ('"$BRANDED_EMAIL_SCRIPT"', '"$BRANDED_DISCORD_SCRIPT"', '"$SENDMAIL_BIN"'):
    if forbidden in dispatch_source:
        fail(f"manual Weather delivery invoked an external notification channel: {forbidden}")

weather_view = (ROOT / "slsmassnotifyserver/views/settings.php").read_text(encoding="utf-8")
for marker in (
    "sls-operation-status",
    "Weather test in progress",
    "Weather test submitted",
    "Weather test needs attention",
    "requestRunning",
):
    if marker not in weather_view:
        fail(f"Weather test progress UI contract is missing: {marker}")

print("Manual Weather channel-aware test contract checks passed.")
