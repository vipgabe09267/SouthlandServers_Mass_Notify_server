#!/usr/bin/env python3
"""Regression checks for Weather.gov alert-chain identity handling."""

import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import textwrap
import time
from pathlib import Path


sys.dont_write_bytecode = True


ROOT = Path(__file__).resolve().parents[1]
SENDER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
POLLER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
MODULE_CLASS = ROOT / "slsmassnotifyserver/Slsmassnotifyserver.class.php"
STATUS_HELPER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_nws_status.py"
DESTINATIONS = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notification_destinations.py"
SPEC = importlib.util.spec_from_file_location("sls_notify_nws_identity", SENDER)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)
STATUS_SPEC = importlib.util.spec_from_file_location("sls_nws_local_dispatch_test", STATUS_HELPER)
STATUS_MODULE = importlib.util.module_from_spec(STATUS_SPEC)
assert STATUS_SPEC.loader is not None
STATUS_SPEC.loader.exec_module(STATUS_MODULE)


def alert(identifier, message_type, references=None):
    return {
        "id": f"https://api.weather.gov/alerts/{identifier}",
        "properties": {
            "event": "Heat Advisory",
            "messageType": message_type,
            "references": references or [],
        },
    }


original = alert("original-alert", "Alert")
assert MODULE.alert_chain_key(original) == "Heat Advisory|original-alert"

# A malformed/provider-added historical reference on a first-issued Alert must
# never make that new alert collide with an old processed chain.
new_with_history = alert(
    "new-alert",
    "Alert",
    [{"identifier": "old-alert", "sent": "2026-07-01T12:00:00Z"}],
)
assert MODULE.alert_chain_key(new_with_history) == "Heat Advisory|new-alert"

first_update = alert(
    "update-one",
    "Update",
    [{"identifier": "original-alert", "sent": "2026-07-31T12:00:00Z"}],
)
assert MODULE.alert_chain_key(first_update) == MODULE.alert_chain_key(original)

# Later updates can reference both the immediately previous update and the
# original. Selecting the earliest reference keeps the whole chain stable.
second_update = alert(
    "update-two",
    "Update",
    [
        {"identifier": "update-one", "sent": "2026-07-31T13:00:00Z"},
        {"identifier": "original-alert", "sent": "2026-07-31T12:00:00Z"},
    ],
)
assert MODULE.alert_chain_key(second_update) == MODULE.alert_chain_key(original)

poller_source = POLLER.read_text(encoding="utf-8")
module_class_source = MODULE_CLASS.read_text(encoding="utf-8")
for marker in (
    "if msg_type.lower() == 'update':",
    "reference_id = min(candidates)[2]",
    "key_source = reference_id or alert_id",
):
    assert marker in poller_source, f"missing poller chain-identity guard: {marker}"

assert "'Heat Advisory'," in module_class_source, "Heat Advisory missing from FreePBX event choices"
assert '"Heat Advisory"' in poller_source, "Heat Advisory missing from poller event map"

# The local journal is atomic, private, append-only within its bounded alert
# history, and fail-closed. A second queue result is recovery, not permission
# to submit the same phone/Desktop work again.
with tempfile.TemporaryDirectory(prefix="sls-nws-local-intent-unit-") as directory:
    state_path = Path(directory) / "local-dispatch.json"
    unit_now = int(time.time())
    assert STATUS_MODULE.queue_local_dispatch_intent(
        state_path,
        "Heat Advisory|intent-unit",
        "intent-unit",
        "Heat Advisory",
        phone_requested=True,
        visual_requested=True,
        now=unit_now,
    )
    assert stat.S_IMODE(state_path.stat().st_mode) == 0o640
    assert STATUS_MODULE.local_dispatch_intent_recorded(state_path, "Heat Advisory|intent-unit")
    assert not STATUS_MODULE.queue_local_dispatch_intent(
        state_path,
        "Heat Advisory|intent-unit",
        "intent-unit",
        "Heat Advisory",
        phone_requested=True,
        visual_requested=True,
        now=unit_now + 1,
    )
    state = json.loads(state_path.read_text(encoding="utf-8"))
    assert len(state["intents"]) == 1

    corrupt_path = Path(directory) / "corrupt-local-dispatch.json"
    corrupt_path.write_bytes(b"{corrupt\n")
    corrupt_before = corrupt_path.read_bytes()
    try:
        STATUS_MODULE.queue_local_dispatch_intent(
            corrupt_path,
            "Heat Advisory|must-not-reset",
            "must-not-reset",
            "Heat Advisory",
            phone_requested=False,
            visual_requested=True,
            now=1002,
        )
    except STATUS_MODULE.LocalDispatchStateError:
        pass
    else:
        raise AssertionError("corrupt local-dispatch state was silently reset")
    assert corrupt_path.read_bytes() == corrupt_before

    capacity_path = Path(directory) / "capacity-local-dispatch.json"
    original_capacity = STATUS_MODULE.MAX_LOCAL_DISPATCH_INTENTS
    STATUS_MODULE.MAX_LOCAL_DISPATCH_INTENTS = 2
    current = int(time.time())
    try:
        for suffix in ("one", "two"):
            assert STATUS_MODULE.queue_local_dispatch_intent(
                capacity_path,
                f"Heat Advisory|capacity-{suffix}",
                suffix,
                "Heat Advisory",
                phone_requested=False,
                visual_requested=True,
                now=current,
            )
        capacity_before = capacity_path.read_bytes()
        try:
            STATUS_MODULE.queue_local_dispatch_intent(
                capacity_path,
                "Heat Advisory|capacity-full",
                "full",
                "Heat Advisory",
                phone_requested=False,
                visual_requested=True,
                now=current,
            )
        except STATUS_MODULE.LocalDispatchStateError as exc:
            assert str(exc) == "local_dispatch_state_capacity_exhausted"
        else:
            raise AssertionError("recent local intents were pruned to bypass capacity")
        assert capacity_path.read_bytes() == capacity_before

        capacity_state = json.loads(capacity_path.read_text(encoding="utf-8"))
        capacity_state["intents"]["Heat Advisory|capacity-one"]["queued_at"] = (
            current - STATUS_MODULE.LOCAL_DISPATCH_RETENTION_SECONDS - 1
        )
        capacity_path.write_text(json.dumps(capacity_state) + "\n", encoding="utf-8")
        capacity_path.chmod(0o640)
        assert STATUS_MODULE.queue_local_dispatch_intent(
            capacity_path,
            "Heat Advisory|capacity-replacement",
            "replacement",
            "Heat Advisory",
            phone_requested=False,
            visual_requested=True,
            now=current,
        )
        pruned = json.loads(capacity_path.read_text(encoding="utf-8"))["intents"]
        assert "Heat Advisory|capacity-one" not in pruned
        assert set(pruned) == {
            "Heat Advisory|capacity-two",
            "Heat Advisory|capacity-replacement",
        }
    finally:
        STATUS_MODULE.MAX_LOCAL_DISPATCH_INTENTS = original_capacity


def write_executable(path, source):
    path.write_text(textwrap.dedent(source).lstrip(), encoding="utf-8")
    path.chmod(0o755)


def run_live_poller_case(
    case_name,
    *,
    prequeue=False,
    corrupt=False,
    external_failure=False,
    external_probe=False,
    crash_after_intent=False,
    crash_after_visual=False,
    repair_journal=False,
    force_replay=False,
):
    """Exercise the real shell flow with local-only fakes and no network."""
    with tempfile.TemporaryDirectory(prefix=f"sls-nws-intent-{case_name}-") as directory:
        root = Path(directory)
        fake_bin = root / "bin"
        fake_bin.mkdir()
        for child in ("tones", "tts", "sounds", "spool", "spool-tmp"):
            (root / child).mkdir()

        identifier = f"{case_name}-alert"
        alert_key = f"Heat Advisory|{identifier}"
        payload_path = root / "nws.json"
        payload_path.write_text(
            json.dumps({
                "type": "FeatureCollection",
                "features": [{
                    "id": f"https://api.weather.gov/alerts/{identifier}",
                    "type": "Feature",
                    "properties": {
                        "event": "Heat Advisory",
                        "severity": "Moderate",
                        "status": "Actual",
                        "messageType": "Alert",
                        "areaDesc": "Test County",
                        "headline": "Synthetic nonnetwork regression payload",
                        "description": "Synthetic nonnetwork regression payload.",
                        "instruction": "Stay hydrated.",
                        "references": [],
                    },
                }],
            }),
            encoding="utf-8",
        )
        config_path = root / "mass-notifications.config"
        config_path.write_text("{}\n", encoding="utf-8")
        loader_path = root / "config-loader.py"
        write_executable(
            loader_path,
            """
            #!/usr/bin/env python3
            import sys
            pairs = (
                ("NWS_ALERTS_ENABLED", "1"),
                ("NWS_API_BASE_URL", "https://api.weather.gov"),
                ("NWS_ZONE", "TXC001"),
                ("QUIET_HOURS_ENABLED", "0"),
                ("LOG_RETENTION_DAYS", "90"),
                ("MAIL_TO", ""),
            )
            sys.stdout.buffer.write(b"".join(
                key.encode() + b"\\0" + value.encode() + b"\\0"
                for key, value in pairs
            ))
            """,
        )
        write_executable(
            fake_bin / "curl",
            """
            #!/bin/bash
            exec /bin/cat "$NWS_TEST_PAYLOAD_SOURCE"
            """,
        )
        destination_path = DESTINATIONS
        if external_failure:
            destination_path = root / "external-failure.py"
            write_executable(
                destination_path,
                """
                #!/usr/bin/env python3
                import os
                raise SystemExit(0 if os.environ.get("SLS_EXTERNAL_RETRY_ONLY") == "1" else 75)
                """,
            )
        external_retry_marker = root / "external-retries.txt"
        if external_probe:
            destination_path = root / "external-order-probe.py"
            write_executable(
                destination_path,
                """
                #!/usr/bin/env python3
                import json
                import os
                from pathlib import Path

                def queue_external_delivery(state_path, _config, correlation_key, *_args, **_kwargs):
                    path = Path(state_path)
                    state = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {"deliveries": {}}
                    state["deliveries"].setdefault(correlation_key, {"completed_at": 0})
                    path.write_text(json.dumps(state) + "\\n", encoding="utf-8")
                    path.chmod(0o640)
                    return correlation_key

                if __name__ == "__main__":
                    if os.environ.get("SLS_EXTERNAL_RETRY_ONLY") != "1":
                        raise SystemExit(75)
                    if not Path(os.environ["NWS_VISUAL_MARKER"]).exists():
                        raise SystemExit("external sender ran before local submission")
                    with Path(os.environ["NWS_EXTERNAL_RETRY_MARKER"]).open("a", encoding="utf-8") as handle:
                        handle.write("after-local\\n")
                    raise SystemExit(0)
                """,
            )
        status_driver = root / "status-helper-driver.py"
        crash_marker = root / "crash-window.txt"
        write_executable(
            status_driver,
            """
            #!/usr/bin/env python3
            import os
            import signal
            import subprocess
            import sys
            import time
            result = subprocess.run([sys.executable, os.environ["NWS_REAL_STATUS_HELPER"], *sys.argv[1:]])
            if (
                result.returncode == 0
                and len(sys.argv) == 2
                and sys.argv[1] == "local-intent"
                and os.environ.get("NWS_CRASH_AFTER_INTENT") == "1"
            ):
                with open(os.environ["NWS_CRASH_MARKER"], "a", encoding="utf-8") as handle:
                    handle.write("after-intent\\n")
                    handle.flush()
                    os.fsync(handle.fileno())
                timeout_parent = os.getppid()
                stat_line = open(f"/proc/{timeout_parent}/stat", encoding="utf-8").read()
                shell_parent = int(stat_line.rsplit(")", 1)[1].split()[1])
                os.kill(shell_parent, signal.SIGKILL)
                time.sleep(0.1)
            raise SystemExit(result.returncode)
            """,
        )
        visual_marker = root / "visual-submissions.txt"
        visual_path = root / "visual.py"
        write_executable(
            visual_path,
            """
            #!/usr/bin/env python3
            import json
            import os
            import signal
            import time
            from pathlib import Path
            state = json.loads(Path(os.environ["LOCAL_DISPATCH_STATE"]).read_text(encoding="utf-8"))
            if os.environ["NWS_EXPECTED_ALERT_KEY"] not in state.get("intents", {}):
                raise SystemExit("visual invoked before durable local intent")
            with Path(os.environ["NWS_VISUAL_MARKER"]).open("a", encoding="utf-8") as handle:
                handle.write(os.environ["NWS_EXPECTED_ALERT_KEY"] + "\\n")
                handle.flush()
                os.fsync(handle.fileno())
            if os.environ.get("NWS_CRASH_AFTER_VISUAL") == "1":
                with Path(os.environ["NWS_CRASH_MARKER"]).open("a", encoding="utf-8") as handle:
                    handle.write("after-visual\\n")
                    handle.flush()
                    os.fsync(handle.fileno())
                timeout_parent = os.getppid()
                stat_line = Path(f"/proc/{timeout_parent}/stat").read_text(encoding="utf-8")
                shell_parent = int(stat_line.rsplit(")", 1)[1].split()[1])
                os.kill(shell_parent, signal.SIGKILL)
                time.sleep(0.1)
            """,
        )

        local_state = root / "local-dispatch.json"
        if prequeue:
            assert STATUS_MODULE.queue_local_dispatch_intent(
                local_state,
                alert_key,
                identifier,
                "Heat Advisory",
                phone_requested=False,
                visual_requested=True,
                now=int(time.time()),
            )
        elif corrupt:
            local_state.write_bytes(b"{corrupt\n")

        environment = os.environ.copy()
        environment.update({
            "PATH": str(fake_bin) + os.pathsep + environment.get("PATH", ""),
            "CONFIG_JSON_FILE": str(config_path),
            "CONFIG_LOADER": str(loader_path),
            "NOTIFICATION_DESTINATION_SCRIPT": str(destination_path),
            "NWS_STATUS_HELPER": str(status_driver),
            "NWS_REAL_STATUS_HELPER": str(STATUS_HELPER),
            "STATUS_FILE": str(root / "status.json"),
            "EXTERNAL_DELIVERY_STATE": str(root / "external-deliveries.json"),
            "LOCAL_DISPATCH_STATE": str(local_state),
            "SEEN_ALERTS": str(root / "seen.txt"),
            "PROCESSED_ALERTS": str(root / "processed.txt"),
            "AUDIO_DELIVERED_ALERTS": str(root / "audio-delivered.txt"),
            "EVENTS_LOG": str(root / "events.jsonl"),
            "LOG": str(root / "poller.log"),
            "LOCK_FILE": str(root / "poller.lock"),
            "LIGHTNING_GATE_FILE": str(root / "lightning-gate.json"),
            "SLS_TONES_DIR": str(root / "tones"),
            "SLS_TTS_DIR": str(root / "tts"),
            "ASTERISK_SOUNDS_DIR": str(root / "sounds"),
            "SPOOL": str(root / "spool"),
            "SPOOL_TMP": str(root / "spool-tmp"),
            "VISUAL_SCRIPT": str(visual_path),
            "NWS_ZONE_OVERRIDE": "TXC001",
            "NWS_RECIPIENTS_OVERRIDE": "",
            "NWS_DESKTOP_CLIENTS_OVERRIDE": "test-desktop",
            "NWS_EMAIL_RECIPIENTS_OVERRIDE": "",
            "NWS_TEST_PAYLOAD_SOURCE": str(payload_path),
            "NWS_EXPECTED_ALERT_KEY": alert_key,
            "NWS_VISUAL_MARKER": str(visual_marker),
            "NWS_CRASH_MARKER": str(crash_marker),
            "NWS_CRASH_AFTER_INTENT": "1" if crash_after_intent else "0",
            "NWS_CRASH_AFTER_VISUAL": "1" if crash_after_visual else "0",
            "NWS_EXTERNAL_RETRY_MARKER": str(external_retry_marker),
            "NWS_ALERTS_DRY_RUN": "0",
            "FORCE_REPLAY": "1" if force_replay else "0",
            "TEST_PAYLOAD": "",
            "PYTHONDONTWRITEBYTECODE": "1",
        })
        result = subprocess.run(
            [str(POLLER)],
            env=environment,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=60,
            check=False,
        )
        if result.returncode != 0:
            log = (root / "poller.log").read_text(encoding="utf-8", errors="replace")
            raise AssertionError(
                f"poller case {case_name} failed ({result.returncode}): "
                f"{result.stderr}\n{log}"
            )
        if crash_after_intent or crash_after_visual:
            assert crash_marker.exists(), f"poller case {case_name} did not reach its crash window"
            # The killed pipeline shell's timeout/helper children briefly retain
            # inherited descriptors, including the worker flock.
            time.sleep(0.75)
            environment["NWS_CRASH_AFTER_INTENT"] = "0"
            environment["NWS_CRASH_AFTER_VISUAL"] = "0"
            restarted = subprocess.run(
                [str(POLLER)],
                env=environment,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=60,
                check=False,
            )
            if restarted.returncode != 0:
                log = (root / "poller.log").read_text(encoding="utf-8", errors="replace")
                raise AssertionError(
                    f"poller recovery {case_name} failed ({restarted.returncode}): "
                    f"{restarted.stderr}\n{log}"
                )
        if repair_journal:
            assert not visual_marker.exists(), "unsafe local journal unexpectedly allowed a local call"
            assert local_state.read_bytes() == b"{corrupt\n"
            local_state.unlink()
            repaired = subprocess.run(
                [str(POLLER)],
                env=environment,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=60,
                check=False,
            )
            if repaired.returncode != 0:
                log = (root / "poller.log").read_text(encoding="utf-8", errors="replace")
                raise AssertionError(
                    f"poller journal repair {case_name} failed ({repaired.returncode}): "
                    f"{repaired.stderr}\n{log}"
                )
        external_path = root / "external-deliveries.json"
        external_recorded = external_path.exists() and bool(
            json.loads(external_path.read_text(encoding="utf-8")).get("deliveries")
        )
        if repair_journal and external_recorded:
            assert len(json.loads(external_path.read_text(encoding="utf-8"))["deliveries"]) == 1
        if external_probe:
            assert external_retry_marker.read_text(encoding="utf-8").splitlines() == ["after-local"]
        status = json.loads((root / "status.json").read_text(encoding="utf-8"))
        processed = (root / "processed.txt").read_text(encoding="utf-8").splitlines()
        visual_submissions = (
            visual_marker.read_text(encoding="utf-8").splitlines()
            if visual_marker.exists()
            else []
        )
        local_bytes = local_state.read_bytes() if local_state.exists() else b""
        return (
            status,
            processed,
            visual_submissions,
            local_bytes,
            alert_key,
            external_recorded,
            crash_marker.read_text(encoding="utf-8").splitlines() if crash_marker.exists() else [],
        )


fresh_status, fresh_processed, fresh_visual, fresh_local, fresh_key, fresh_external, _ = run_live_poller_case("fresh")
assert fresh_visual == [fresh_key]
assert fresh_key in fresh_processed
assert fresh_status["last_delivery_status"] == "queued"
assert fresh_key in json.loads(fresh_local)["intents"]
assert fresh_external

recovery_status, recovery_processed, recovery_visual, _, recovery_key, recovery_external, _ = run_live_poller_case(
    "recovery", prequeue=True
)
assert recovery_visual == [], "restart replayed a local visual submission after durable intent"
assert recovery_key in recovery_processed
assert recovery_status["last_delivery_status"] == "indeterminate"
assert "not replayed" in recovery_status["last_delivery_message"]
assert recovery_external

failed_status, failed_processed, failed_visual, failed_local, failed_key, failed_external, _ = run_live_poller_case(
    "journal-failure", corrupt=True
)
assert failed_visual == [], "local journal failure still invoked a local submission"
assert failed_key not in failed_processed
assert failed_status["last_delivery_status"] == "failed"
assert "zero local phone or visual submissions" in failed_status["last_delivery_message"]
assert failed_local == b"{corrupt\n", "fail-closed journal handling rewrote corrupt state"
assert failed_external

external_status, external_processed, external_visual, _, external_key, external_recorded, _ = run_live_poller_case(
    "external-failure", external_failure=True
)
assert external_visual == [], "external state exit 75 still invoked a local submission"
assert external_key not in external_processed
assert external_status["last_delivery_status"] == "failed"
assert not external_recorded

intent_status, intent_processed, intent_visual, _, intent_key, intent_external, intent_crashes = run_live_poller_case(
    "crash-after-intent", crash_after_intent=True
)
assert intent_crashes == ["after-intent"]
assert intent_visual == [], "restart after intent replayed a local visual submission"
assert intent_key in intent_processed and intent_external, (
    intent_status, intent_processed, intent_visual, intent_external, intent_crashes
)
assert intent_status["last_delivery_status"] == "indeterminate"

visual_status, visual_processed, visual_calls, _, visual_key, visual_external, visual_crashes = run_live_poller_case(
    "crash-after-visual", crash_after_visual=True
)
assert visual_crashes == ["after-visual"]
assert visual_calls == [visual_key], "restart replayed the first accepted local submission"
assert visual_key in visual_processed and visual_external
assert visual_status["last_delivery_status"] == "indeterminate"

probe_status, probe_processed, probe_visual, _, probe_key, probe_external, _ = run_live_poller_case(
    "post-local-external-probe", external_probe=True
)
assert probe_visual == [probe_key] and probe_key in probe_processed and probe_external
assert probe_status["last_delivery_status"] == "queued"

repair_status, repair_processed, repair_visual, _, repair_key, repair_external, _ = run_live_poller_case(
    "journal-repair", corrupt=True, repair_journal=True
)
assert repair_visual == [repair_key], "repaired journal did not permit the never-attempted local work"
assert repair_key in repair_processed and repair_external
assert repair_status["last_delivery_status"] == "queued"

force_status, force_processed, force_visual, _, force_key, force_external, _ = run_live_poller_case(
    "operator-force-replay", prequeue=True, force_replay=True
)
assert force_visual == [force_key], "explicit FORCE_REPLAY did not bypass automatic recovery suppression"
assert force_key not in force_processed, "operator replay was silently committed as automatic dedup state"
assert not force_external, "operator-only local replay unexpectedly queued external work"
assert force_status["last_delivery_status"] == "queued"

# Source order backs the behavioral fakes: external task first, local intent
# second, and both irreversible local calls strictly after those markers.
external_stage = poller_source.index("# Stage 1: durable external work")
external_queue = poller_source.index('queue_external_destinations "$MAIL_SUBJECT"', external_stage)
intent_stage = poller_source.index("# Stage 2: persist intent", external_queue)
intent_queue = poller_source.index('queue_local_dispatch_intent "$ALERT_KEY"', intent_stage)
audio_queue = poller_source.index('queue_audio_to_recipients "$AUDIO_SEQUENCE"', intent_queue)
visual_queue = poller_source.index('trigger_visual_alert "$ALERT_B64"', audio_queue)
retry_stage = poller_source.index("# Stage 3 runs only after every actionable alert", visual_queue)
retry_call = poller_source.index("retry_pending_external_destinations", retry_stage)
assert external_stage < external_queue < intent_stage < intent_queue < audio_queue < visual_queue < retry_stage < retry_call
for marker in (
    'DELIVERY_STATUS="indeterminate"',
    "automatic local replay is suppressed",
    'if [ "$FORCE_REPLAY" = "1" ]; then',
    'LOCAL_DISPATCH_STATE="$DATA_DIR/local-dispatch-intents-${safe_id}.json"',
):
    source = poller_source if marker != 'LOCAL_DISPATCH_STATE="$DATA_DIR/local-dispatch-intents-${safe_id}.json"' else (
        ROOT / "slsmassnotifyserver/bin/sls_mass_notify_weather_poll.sh"
    ).read_text(encoding="utf-8")
    assert marker in source, f"missing NWS local at-most-once marker: {marker}"

print("NWS alert-chain deduplication regressions passed.")
