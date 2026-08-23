#!/usr/bin/env python3
"""Deterministic regressions for concurrent multi-zone Weather.gov status."""

from __future__ import annotations

import importlib.util
import json
import multiprocessing
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_nws_status.py"
POLLER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_nws_poll.sh"
WEATHER_WRAPPER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_weather_poll.sh"


def load_helper():
    spec = importlib.util.spec_from_file_location("sls_nws_status", HELPER)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def concurrent_failures(status_file: str, group_id: str, iterations: int, barrier) -> None:
    helper = load_helper()
    barrier.wait()
    for index in range(iterations):
        helper.mutate_status(
            Path(status_file),
            group_id,
            "Concurrent Zone",
            "TXC003",
            {
                "api_failure": {
                    "at": f"2026-08-22T12:{index % 60:02d}:00+00:00",
                    "message": "simulated concurrent failure",
                    "threshold": 100,
                }
            },
        )


def main() -> None:
    poller_source = POLLER.read_text(encoding="utf-8")
    wrapper_source = WEATHER_WRAPPER.read_text(encoding="utf-8")
    for marker in (
        "sls_nws_status.py",
        "run_status_mutation",
        "clear_fault_stage",
        "api_failure",
    ):
        assert marker in poller_source, f"NWS poller is missing status integration: {marker}"
    for marker in ("NWS_CONFIGURED_GROUP_IDS_JSON", "sls_nws_status.py", '"$status_helper" reconcile'):
        assert marker in wrapper_source, f"Weather wrapper is missing status reconciliation: {marker}"
    assert "get_status_value()" not in poller_source

    helper = load_helper()
    with tempfile.TemporaryDirectory() as directory:
        status_file = Path(directory) / "status.json"
        helper.reconcile_status(status_file, ["zone_a", "zone_b"])

        # Preserve faults independently: a success from zone B must not clear
        # the unresolved API fault owned by zone A.
        helper.mutate_status(
            status_file,
            "zone_a",
            "North Zone",
            "TXC001",
            {
                "api_failure": {
                    "at": "2026-08-22T10:00:00+00:00",
                    "message": "zone A API unavailable",
                    "threshold": 1,
                }
            },
        )
        helper.mutate_status(
            status_file,
            "zone_b",
            "South Zone",
            "TXC002",
            {
                "patch": {
                    "last_poll_at": "2026-08-22T10:01:00+00:00",
                    "last_poll_status": "ok",
                    "last_poll_message": "zone B poll succeeded",
                    "last_poll_ok_at": "2026-08-22T10:01:00+00:00",
                    "last_poll_feature_count": 3,
                    "last_poll_candidate_count": 2,
                    "last_poll_events": {"Heat Advisory": 3},
                    "last_poll_candidate_events": {"Heat Advisory": 2},
                },
                "reset_api": True,
            },
        )
        state = json.loads(status_file.read_text(encoding="utf-8"))
        assert state["last_poll_status"] == "fault"
        assert state["last_fault_group_id"] == "zone_a"
        assert state["last_fault_stage"] == "api"
        assert state["nws_groups"]["zone_b"]["last_poll_status"] == "ok"
        assert state["last_poll_feature_count"] == 3

        # A non-NWS fault and the most recent non-NWS delivery remain owned by
        # their original subsystem when an ordinary NWS poll is recorded.
        state.update({
            "last_fault_at": "2026-08-22T10:02:00+00:00",
            "last_fault_stage": "dependencies",
            "last_fault_message": "Piper is unavailable",
            "last_fault_source": "",
            "last_delivery_at": "2026-08-22T10:02:00+00:00",
            "last_delivery_source": "announcement",
            "last_delivery_status": "queued",
            "last_delivery_message": "announcement queued",
        })
        status_file.write_text(json.dumps(state), encoding="utf-8")
        helper.mutate_status(
            status_file,
            "zone_b",
            "South Zone",
            "TXC002",
            {
                "patch": {
                    "last_poll_at": "2026-08-22T10:03:00+00:00",
                    "last_poll_status": "ok",
                    "last_poll_message": "zone B poll succeeded again",
                }
            },
        )
        state = json.loads(status_file.read_text(encoding="utf-8"))
        assert state["last_fault_stage"] == "dependencies"
        assert state["last_fault_message"] == "Piper is unavailable"
        assert state["last_delivery_source"] == "announcement"
        assert state["last_delivery_message"] == "announcement queued"

        # Clear the external fields to let the per-zone aggregate become the
        # backward-compatible global fault again.
        for key in (
            "last_fault_at",
            "last_fault_stage",
            "last_fault_message",
            "last_fault_source",
        ):
            state[key] = ""
        status_file.write_text(json.dumps(state), encoding="utf-8")
        helper.mutate_status(
            status_file,
            "zone_b",
            "South Zone",
            "TXC002",
            {"patch": {"last_poll_at": "2026-08-22T10:04:00+00:00"}},
        )
        state = json.loads(status_file.read_text(encoding="utf-8"))
        assert state["last_fault_group_id"] == "zone_a"

        # Removing a configured zone prunes its status and re-derives the
        # legacy fields without leaving that zone's fault behind.
        helper.reconcile_status(status_file, ["zone_b"])
        state = json.loads(status_file.read_text(encoding="utf-8"))
        assert set(state["nws_groups"]) == {"zone_b"}
        assert state["last_fault_at"] == ""
        assert state["last_poll_status"] == "ok"

        # A worker launched by the previous scheduler cycle cannot resurrect
        # a group after the authoritative reconciliation removed it.
        helper.mutate_status(
            status_file,
            "zone_a",
            "North Zone",
            "TXC001",
            {"patch": {"last_poll_at": "2026-08-22T10:05:00+00:00", "last_poll_status": "fault"}},
        )
        state = json.loads(status_file.read_text(encoding="utf-8"))
        assert set(state["nws_groups"]) == {"zone_b"}
        assert state["last_poll_status"] == "ok"

    # The read/increment/write sequence itself must be atomic.  All processes
    # begin together and contend on the same real status-file lock.
    with tempfile.TemporaryDirectory() as directory:
        status_file = Path(directory) / "status.json"
        helper.reconcile_status(status_file, ["zone_concurrent"])
        process_count = 8
        iterations = 25
        context = multiprocessing.get_context("fork")
        barrier = context.Barrier(process_count)
        processes = [
            context.Process(
                target=concurrent_failures,
                args=(str(status_file), "zone_concurrent", iterations, barrier),
            )
            for _ in range(process_count)
        ]
        for process in processes:
            process.start()
        for process in processes:
            process.join(30)
            assert process.exitcode == 0, f"status worker exited {process.exitcode}"
        state = json.loads(status_file.read_text(encoding="utf-8"))
        expected = process_count * iterations
        assert state["nws_groups"]["zone_concurrent"]["last_poll_fail_count"] == expected
        assert state["last_poll_fail_count"] == expected
        assert state["last_fault_group_id"] == "zone_concurrent"

    print("Atomic multi-zone Weather.gov status regressions passed.")


if __name__ == "__main__":
    main()
