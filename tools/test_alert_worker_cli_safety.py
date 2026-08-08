#!/usr/bin/env python3
"""Ensure informational or invalid CLI arguments cannot trigger alert workers."""

import os
import pathlib
import subprocess
import tempfile


ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKERS = (
    ("bash", ROOT / "slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"),
    ("bash", ROOT / "slsmassnotifyserver/bin/sls_mass_notify_test.sh"),
    ("bash", ROOT / "slsmassnotifyserver/bin/sls_mass_notify_weather_poll.sh"),
    ("python3", ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py"),
)


def run_guarded(interpreter, worker, argument, expected_status):
    with tempfile.TemporaryDirectory(prefix="sls-cli-safety-") as directory:
        scratch = pathlib.Path(directory)
        env = os.environ.copy()
        env.update(
            {
                "PYTHONDONTWRITEBYTECODE": "1",
                "CONFIG_FILE": str(scratch / "missing.config"),
                "CONFIG_JSON_FILE": str(scratch / "missing.config"),
                "DATA_DIR": str(scratch / "data"),
                "EVENTS_LOG": str(scratch / "events.jsonl"),
                "FAULT_STATE_FILE": str(scratch / "fault.state"),
                "LOCK_FILE": str(scratch / "poll.lock"),
                "LOG": str(scratch / "worker.log"),
                "STATUS_FILE": str(scratch / "status.json"),
                "XWEATHER_STATE_FILE": str(scratch / "xweather.json"),
            }
        )
        result = subprocess.run(
            [interpreter, str(worker), argument],
            cwd=ROOT,
            env=env,
            text=True,
            capture_output=True,
            timeout=8,
            check=False,
        )
        if result.returncode != expected_status:
            raise AssertionError(
                f"{worker.name} {argument} returned {result.returncode}: "
                f"{result.stdout} {result.stderr}"
            )
        combined = (result.stdout + result.stderr).lower()
        if "usage:" not in combined:
            raise AssertionError(f"{worker.name} {argument} did not print usage")
        created = list(scratch.rglob("*"))
        if created:
            raise AssertionError(
                f"{worker.name} {argument} created side effects: "
                + ", ".join(str(path.relative_to(scratch)) for path in created)
            )


for runtime, path in WORKERS:
    run_guarded(runtime, path, "--help", 0)
    run_guarded(runtime, path, "--invalid-safety-probe", 2)

test_sender = (ROOT / "slsmassnotifyserver/bin/sls_mass_notify_test.sh").read_text(
    encoding="utf-8"
)
if "send_notification_email()" in test_sender or "send_discord_alert()" in test_sender:
    raise AssertionError("manual Weather test still defines an external notification sender")

fault_start = test_sender.index("report_fault() {")
fault_end = test_sender.index("\n}\n", fault_start)
fault_handler = test_sender[fault_start:fault_end]
for forbidden in ("last_fault_", "fault_email_sent_at", "FAULT_STATE_FILE", "BRANDED_EMAIL_SCRIPT"):
    if forbidden in fault_handler:
        raise AssertionError(f"manual Weather test fault handler contains {forbidden}")
for required in ('"last_test_status":"fault"', "last_test_stage", "last_test_message"):
    if required not in fault_handler:
        raise AssertionError(f"manual Weather test fault handler is missing {required}")

print("Alert-worker CLI safety and manual-test fault isolation checks passed.")
