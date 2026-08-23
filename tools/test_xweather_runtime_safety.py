#!/usr/bin/env python3
"""Focused atomic-state, redirect, and weather.gov-boundary regressions."""

import importlib.util
import os
import sys
import tempfile
import urllib.request
from pathlib import Path
from unittest import mock


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


worker = load("sls_xweather_runtime_safety", RUNTIME / "sls_mass_notify_xweather_poll.py")
config_loader = load("sls_config_runtime_safety", RUNTIME / "sls_config.py")


def fail(message):
    raise AssertionError(message)


with tempfile.TemporaryDirectory(prefix="sls-xweather-state-") as directory:
    state_path = Path(directory) / "xweather-state.json"
    state_path.write_text('{"active":true,"notified":false}\n', encoding="utf-8")
    os.chmod(state_path, 0o640)
    with mock.patch.object(worker, "STATE_FILE", state_path):
        worker.atomic_json_update(state_path, {"notified": True, "last_notification": 123})
        merged = worker.read_state()
    if merged.get("active") is not True or merged.get("notified") is not True:
        fail("atomic Xweather state update did not preserve and merge existing fields")
    if (state_path.stat().st_mode & 0o777) != 0o640:
        fail("atomic Xweather state replacement did not retain mode 0640")

    before = state_path.read_bytes()
    with mock.patch.object(worker.os, "replace", side_effect=OSError("injected replacement failure")):
        try:
            worker._atomic_state_update(state_path, {"notified": False})
        except OSError:
            pass
        else:
            fail("injected atomic replacement failure did not fail")
    if state_path.read_bytes() != before:
        fail("failed Xweather replacement truncated or rewrote the prior state")
    leftovers = [path for path in state_path.parent.iterdir() if path.name.startswith(f".{state_path.name}.")]
    if leftovers:
        fail(f"failed Xweather replacement left temporary files: {leftovers}")

    state_path.write_text("{corrupt\n", encoding="utf-8")
    with mock.patch.object(worker, "STATE_FILE", state_path):
        try:
            worker.read_state()
        except RuntimeError:
            pass
        else:
            fail("corrupt Lightning dedup state was treated as an empty cluster")


handler = worker._RejectRedirects()
try:
    handler.redirect_request(
        urllib.request.Request("https://data.api.xweather.com/lightning/closest"),
        None,
        302,
        "Found",
        {},
        "http://127.0.0.1/private",
    )
except RuntimeError as exc:
    if str(exc) != "redirect_blocked":
        fail(f"Xweather redirect failed with the wrong category: {exc}")
else:
    fail("Xweather client accepted a redirect target")


if config_loader.validated_https_url("https://api.weather.gov", "https://api.weather.gov") != "https://api.weather.gov":
    fail("canonical Weather.gov origin was rejected")
for unsafe in (
    "https://127.0.0.1",
    "https://api.weather.gov.example.com",
    "https://api.weather.gov/internal",
    "https://api.weather.gov:444",
):
    try:
        config_loader.validated_https_url(unsafe, "https://api.weather.gov")
    except ValueError:
        pass
    else:
        fail(f"noncanonical NWS origin was accepted: {unsafe}")


xweather_source = (RUNTIME / "sls_mass_notify_xweather_poll.py").read_text(encoding="utf-8")
generation_position = xweather_source.index("sound = generate_audio(", xweather_source.index("def main()"))
intent_position = xweather_source.index(
    'state["local_dispatch_intent"] = correlation_key', generation_position
)
queue_position = xweather_source.index("delivery_key = queue_current_external_delivery()", intent_position)
audio_position = xweather_source.index(
    "queued, archived_results, page_hold_seconds = queue_audio(", queue_position
)
visual_position = xweather_source.index("send_visual(", audio_position)
commit_position = xweather_source.index("commit_local_event_state(state, event_kind, now)", queue_position)
retry_position = xweather_source.index("retry_outcome = retry_external_now(correlation_key)", commit_position)
if not generation_position < intent_position < queue_position < audio_position < visual_position < commit_position < retry_position:
    fail("Xweather did not generate audio, persist intent, submit locally, commit dedup, and retry in order")


with tempfile.TemporaryDirectory(prefix="sls-xweather-crash-") as directory:
    crash_dir = Path(directory)
    state_path = crash_dir / "xweather-state.json"
    retry_path = crash_dir / "external-deliveries.json"
    status_path = crash_dir / "status.json"
    log_path = crash_dir / "worker.log"
    events_path = crash_dir / "events.jsonl"
    config = {
        "mail_to": "",
        "discord_webhooks": [],
        "generic_webhooks": [],
    }
    xweather = {
        "enabled": "1",
        "client_id": "client-id",
        "client_secret": "client-secret",
        "location": "Test Location",
        "recipients": ["1001"],
        "adaptive_free_tier": "0",
        "query_interval_minutes": 1,
        "radius_miles": 25,
    }
    first_poll_time = int(worker.time.time())
    local_deliveries = {"audio": 0, "visual": 0}

    def queue_audio(*_args, **_kwargs):
        local_deliveries["audio"] += 1
        return 1, [], 0

    def send_visual(*_args, **_kwargs):
        local_deliveries["visual"] += 1

    def crash_before_commit(*_args, **_kwargs):
        raise RuntimeError("injected crash before local dedup commit")

    real_queue_external_delivery = worker.queue_external_delivery
    real_retry_external_deliveries = worker.retry_external_deliveries

    class InjectedCrash(BaseException):
        pass

    common_patches = (
        mock.patch.object(worker, "STATE_FILE", state_path),
        mock.patch.object(worker, "STATE_FILE_EXPLICIT", True),
        mock.patch.object(worker, "EXTERNAL_DELIVERY_STATE_FILE", retry_path),
        mock.patch.object(worker, "STATUS_FILE", status_path),
        mock.patch.object(worker, "LOG_FILE", log_path),
        mock.patch.object(worker, "EVENTS_LOG", events_path),
        mock.patch.object(worker, "load_config", return_value=(config, xweather)),
        mock.patch.object(worker, "fetch_payload", return_value={}),
        mock.patch.object(worker, "normalize_records", return_value=[{"id": "strike-1"}]),
        mock.patch.object(worker, "nearest_strike_miles", return_value=1.0),
        mock.patch.object(worker, "quiet_hours_active", return_value=False),
        mock.patch.object(worker, "generate_audio", return_value="test/sound"),
        mock.patch.object(worker, "queue_audio", side_effect=queue_audio),
        mock.patch.object(worker, "send_visual", side_effect=send_visual),
        mock.patch.object(worker.time, "sleep", return_value=None),
    )
    for patcher in common_patches:
        patcher.start()
    try:
        with mock.patch.dict(worker.os.environ, {"XWEATHER_DRY_RUN": "0"}, clear=True):
            with mock.patch.object(worker.time, "time", return_value=first_poll_time):
                with mock.patch.object(worker, "commit_local_event_state", side_effect=crash_before_commit):
                    try:
                        worker.main()
                    except RuntimeError as exc:
                        if str(exc) != "injected crash before local dedup commit":
                            raise
                    else:
                        fail("injected queue-before-commit crash did not stop the first Lightning run")

            persisted = worker.read_state()
            if persisted.get("cluster_started") != first_poll_time or persisted.get("notified") is not False:
                fail("new Lightning cluster identity was not persisted in a retryable state before delivery")
            if persisted.get("local_dispatch_intent") != f"entry:{first_poll_time}":
                fail("crash after local submission did not retain the at-most-once local intent")
            if not worker.external_delivery_recorded(
                retry_path, "xweather", f"entry:{first_poll_time}"
            ):
                fail("queue-before-commit crash did not leave its durable external delivery marker")
            if local_deliveries != {"audio": 1, "visual": 1}:
                fail(f"first Lightning delivery did not reach both local channels once: {local_deliveries}")

            with mock.patch.object(worker.time, "time", return_value=first_poll_time + 61):
                if worker.main() != 0:
                    fail("Lightning restart recovery did not complete successfully")
            if local_deliveries != {"audio": 1, "visual": 1}:
                fail(f"Lightning restart replayed a local channel after durable queueing: {local_deliveries}")
            recovered = worker.read_state()
            if recovered.get("notified") is not True:
                fail("Lightning restart did not commit recovered local dedup state")

            # An abrupt stop after the durable intent but before the first local
            # call must conservatively retain the intent.  Recovery cannot know
            # whether Asterisk accepted a target, so it must not replay either
            # local channel even though this injection happens just before them.
            for path in (state_path, retry_path, status_path, log_path, events_path):
                path.unlink(missing_ok=True)
            local_deliveries.update(audio=0, visual=0)
            intent_crash_time = first_poll_time + 120
            # Simulate an upgrade with a live pre-0.1.0 cluster that has no
            # cluster_started identity. The worker must migrate it before the
            # intent key is derived so restart correlation stays stable.
            state_path.write_text(
                '{"active":true,"notified":false,"empty_polls":0,"last_query":0}\n',
                encoding="utf-8",
            )

            def queue_intent_then_crash(*args, **kwargs):
                real_queue_external_delivery(*args, **kwargs)
                raise InjectedCrash("injected crash after durable dispatch intent")

            with mock.patch.object(worker, "queue_external_delivery", side_effect=queue_intent_then_crash):
                with mock.patch.object(worker.time, "time", return_value=intent_crash_time):
                    try:
                        worker.main()
                    except InjectedCrash as exc:
                        if str(exc) != "injected crash after durable dispatch intent":
                            raise
                    else:
                        fail("injected post-intent Lightning crash did not stop the first run")
            if local_deliveries != {"audio": 0, "visual": 0}:
                fail(f"post-intent crash reached a local channel: {local_deliveries}")
            if not worker.external_delivery_recorded(
                retry_path, "xweather", f"entry:{intent_crash_time}"
            ):
                fail("post-intent crash did not leave its durable local dispatch marker")
            if worker.read_state().get("cluster_started") != intent_crash_time:
                fail("legacy Lightning state did not receive a stable cluster identity before intent")
            if worker.read_state().get("local_dispatch_intent") != f"entry:{intent_crash_time}":
                fail("post-intent crash did not retain the dedicated local dispatch marker")

            with mock.patch.object(worker, "normalize_records", return_value=[]):
                with mock.patch.object(worker.time, "time", return_value=intent_crash_time + 61):
                    if worker.main() != 0:
                        fail("post-intent Lightning restart recovery did not complete successfully")
            if local_deliveries != {"audio": 0, "visual": 0}:
                fail(f"post-intent Lightning restart replayed a local channel: {local_deliveries}")
            recovery_status = worker.read_json_object(status_path)
            if "indeterminate" not in str(recovery_status.get("last_xweather_delivery_message", "")).lower():
                fail("post-intent recovery did not disclose the indeterminate local outcome")

            # If persistence itself fails, the worker still knows that no local
            # call has begun.  It must leave cluster state retryable and submit
            # zero phone/Desktop targets.
            for path in (state_path, retry_path, status_path, log_path, events_path):
                path.unlink(missing_ok=True)
            local_deliveries.update(audio=0, visual=0)
            queue_failure_time = first_poll_time + 240
            with mock.patch.object(
                worker,
                "queue_external_delivery",
                side_effect=worker.RetryStateError("injected dispatch-intent failure"),
            ):
                with mock.patch.object(worker.time, "time", return_value=queue_failure_time):
                    if worker.main() != 1:
                        fail("dispatch-intent persistence failure did not fail the Lightning run")
            if local_deliveries != {"audio": 0, "visual": 0}:
                fail(f"dispatch-intent persistence failure reached a local channel: {local_deliveries}")
            retryable = worker.read_state()
            if retryable.get("cluster_started") != queue_failure_time or retryable.get("notified") is not False:
                fail("dispatch-intent persistence failure did not leave a retryable Lightning cluster")
            if retryable.get("local_dispatch_intent"):
                fail("known pre-submission queue failure retained an unused local dispatch marker")
            failure_status = worker.read_json_object(status_path)
            if "no phone or desktop delivery was attempted" not in str(
                failure_status.get("last_xweather_delivery_message", "")
            ).lower():
                fail("dispatch-intent persistence failure did not report zero local submissions")

            # A due Lightning event must cross both urgent local submission
            # boundaries before any external network retry begins. The next
            # interval-cooldown run still gives durable external work a bounded
            # retry opportunity because no local event is due.
            delivery_order = []

            def ordered_audio(*args, **kwargs):
                delivery_order.append("audio")
                return queue_audio(*args, **kwargs)

            def ordered_visual(*args, **kwargs):
                delivery_order.append("visual")
                return send_visual(*args, **kwargs)

            def ordered_retry(*args, **kwargs):
                delivery_order.append("retry")
                return real_retry_external_deliveries(*args, **kwargs)

            with mock.patch.object(worker, "queue_audio", side_effect=ordered_audio):
                with mock.patch.object(worker, "send_visual", side_effect=ordered_visual):
                    with mock.patch.object(worker, "retry_external_deliveries", side_effect=ordered_retry):
                        with mock.patch.object(worker.time, "time", return_value=queue_failure_time + 61):
                            if worker.main() != 0:
                                fail("healthy Lightning retry after a queue-state failure did not succeed")
            if delivery_order != ["audio", "visual", "retry"]:
                fail(f"external retry delayed a due local Lightning submission: {delivery_order}")

            delivery_order.clear()
            with mock.patch.object(worker, "retry_external_deliveries", side_effect=ordered_retry):
                with mock.patch.object(worker.time, "time", return_value=queue_failure_time + 62):
                    if worker.main() != 0:
                        fail("Lightning interval-cooldown external retry did not succeed")
            if delivery_order != ["retry"]:
                fail(f"Lightning interval cooldown skipped or duplicated its bounded external retry: {delivery_order}")
    finally:
        for patcher in reversed(common_patches):
            patcher.stop()

nws_source = (RUNTIME.parent / "sls_mass_notify_nws_poll.sh").read_text(encoding="utf-8")
if '[ "$NWS_API_BASE_URL" != "https://api.weather.gov" ]' not in nws_source:
    fail("NWS shell worker lacks its independent canonical-origin gate")

print("Xweather atomic-state, redirect, and Weather.gov-boundary regressions passed.")
