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

pathlib.Path(os.environ["VISUAL_ARGS_FILE"]).write_text(
    json.dumps(sys.argv[1:]), encoding="utf-8"
)
raise SystemExit(int(os.environ.get("VISUAL_EXIT", "0")))
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
                "CONFIG_JSON_FILE": str(config),
                "CONFIG_LOADER": str(config_loader),
                "COOLDOWN_FILE": str(cooldown_file),
                "EVENTS_LOG": str(scratch / "events.jsonl"),
                "LOG": str(scratch / "worker.log"),
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
        deadline = time.monotonic() + 20
        while process.poll() is None and time.monotonic() < deadline:
            for call_file in spool.glob("sls_test_*.call"):
                if pick_up:
                    captured_calls.append(call_file.read_text(encoding="utf-8"))
                    call_file.unlink()
            time.sleep(0.02)
        if process.poll() is None:
            process.kill()
            fail("manual Weather test contract case timed out")
        stdout, stderr = process.communicate(timeout=2)
        status = json.loads((scratch / "status.json").read_text(encoding="utf-8"))
        args = (
            json.loads(visual_args.read_text(encoding="utf-8"))
            if visual_args.exists()
            else []
        )
        return {
            "returncode": process.returncode,
            "stdout": stdout,
            "stderr": stderr,
            "status": status,
            "visual_args": args,
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
if "--api-only" in mixed["visual_args"] or "--no-api" in mixed["visual_args"]:
    fail("mixed-channel Weather test disabled one of its requested channels")
for expected in ("--targets", "1000", "--require-all-targets", "--desktop-targets", "desk_ops"):
    if expected not in mixed["visual_args"]:
        fail(f"mixed-channel Weather test omitted {expected}")

visual_failure = run_case([], ["desk_ops"], visual_exit=1)
if visual_failure["returncode"] == 0:
    fail("desktop publication failure was incorrectly reported as success")
if visual_failure["status"].get("last_test_stage") != "visual":
    fail("desktop publication failure did not preserve its failure stage")

pickup_failure = run_case(["1000"], [], pick_up=False)
if pickup_failure["returncode"] == 0:
    fail("unconsumed Asterisk audio job was incorrectly reported as success")
if pickup_failure["status"].get("last_test_stage") != "audio":
    fail("Asterisk audio pickup failure did not preserve its failure stage")
if pickup_failure["visual_args"]:
    fail("visual delivery continued after an audio queue pickup failure")

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

print("Manual Weather channel-aware test contract checks passed.")
