#!/usr/bin/env python3
"""Focused Lightning trigger-area dispatcher, routing, and quota regressions."""

import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))
SPEC = importlib.util.spec_from_file_location("sls_xweather_groups", RUNTIME / "sls_mass_notify_xweather_poll.py")
worker = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(worker)


def fixture_config():
    return {
        "enabled": "1",
        "client_id": "client",
        "client_secret": "secret",
        "adaptive_free_tier": "1",
        "query_interval_minutes": 5,
        "groups": [
            {
                "id": "north",
                "name": "North Campus",
                "enabled": "1",
                "adaptive_nws_zone_id": "nws_north",
                "location": "North Campus",
                "radius_miles": 10,
                "extensions": ["1000"],
                "desktop_clients": ["north-desk"],
                "email_recipients": ["north@example.com"],
                "all_clear": "send",
            },
            {
                "id": "south",
                "name": "South Campus",
                "enabled": "1",
                "adaptive_nws_zone_id": "nws_south",
                "location": "30.1,-97.1",
                "radius_miles": 7,
                "extensions": ["1001"],
                "desktop_clients": ["south-desk"],
                "email_recipients": [],
                "all_clear": "none",
            },
        ],
    }


class XweatherGroupTests(unittest.TestCase):
    def test_legacy_email_merge_is_validated_case_insensitive_and_bounded(self):
        merged = worker._merge_email_recipients(
            ["Configured@Example.com", "invalid"],
            ["configured@example.com", "legacy@example.net", "bad address"],
        )
        self.assertEqual(merged, ["Configured@Example.com", "legacy@example.net"])
        oversized = worker._merge_email_recipients(
            ["first@example.com"],
            [f"legacy{index}@example.net" for index in range(80)],
        )
        self.assertEqual(len(oversized), 50)
        self.assertEqual(oversized[0], "first@example.com")

    def test_groups_inherit_shared_settings_and_keep_routes_isolated(self):
        groups = worker.configured_groups(fixture_config())
        self.assertEqual([group["id"] for group in groups], ["north", "south"])
        self.assertEqual(groups[0]["client_id"], "client")
        self.assertEqual(groups[0]["recipients"], ["1000"])
        self.assertEqual(groups[0]["desktop_clients"], ["north-desk"])
        self.assertEqual(groups[1]["recipients"], ["1001"])
        self.assertEqual(groups[1]["radius_miles"], 7)

    def test_legacy_singleton_uses_the_php_compatible_selection_id(self):
        legacy = {
            "enabled": "1",
            "location": "Round Rock, TX",
            "radius_miles": 10,
            "recipients": ["1000"],
        }
        groups = worker.configured_groups(legacy)
        self.assertEqual(len(groups), 1)
        self.assertEqual(groups[0]["id"], "lightning_primary")
        self.assertTrue(groups[0]["_legacy_singleton"])
        self.assertEqual(worker.select_group(legacy, "lightning_primary")["recipients"], ["1000"])

    def test_dispatcher_runs_each_enabled_area_and_honors_manual_selection(self):
        calls = []

        def run_one():
            calls.append(os.environ.get("XWEATHER_ACTIVE_GROUP_ID", ""))
            return 0

        with mock.patch.object(worker, "load_config", return_value=({}, fixture_config())), \
                mock.patch.object(worker, "main", side_effect=run_one), \
                mock.patch.dict(worker.os.environ, {}, clear=True):
            self.assertEqual(worker.run_configured_group_cycle(), 0)
        self.assertEqual(calls, ["north", "south"])

        calls.clear()
        with mock.patch.object(worker, "load_config", return_value=({}, fixture_config())), \
                mock.patch.object(worker, "main", side_effect=run_one), \
                mock.patch.dict(worker.os.environ, {"XWEATHER_TEST_EVENT": "entry", "XWEATHER_GROUP_IDS": "south"}, clear=True):
            self.assertEqual(worker.run_configured_group_cycle(), 0)
        self.assertEqual(calls, ["south"])

    def test_group_state_paths_are_stable_and_isolated(self):
        with tempfile.TemporaryDirectory(prefix="sls-xweather-groups-") as directory:
            data = Path(directory)
            with mock.patch.object(worker, "DATA_DIR", data), \
                    mock.patch.object(worker, "LEGACY_STATE_FILE", data / "legacy.json"), \
                    mock.patch.object(worker, "STATE_FILE_EXPLICIT", False):
                worker.configure_group_runtime(worker.select_group(fixture_config(), "north"))
                north = worker.STATE_FILE
                worker.configure_group_runtime(worker.select_group(fixture_config(), "south"))
                south = worker.STATE_FILE
            self.assertNotEqual(north, south)
            self.assertEqual(north.name, "xweather-lightning-state-north.json")
            self.assertEqual(south.name, "xweather-lightning-state-south.json")

    def test_visual_delivery_targets_only_the_area_desktops(self):
        with mock.patch.object(worker.subprocess, "run") as run:
            worker.send_visual(["1000"], ["north-desk"], "Test", is_test=True)
        command = run.call_args.args[0]
        self.assertEqual(command[command.index("--targets") + 1], "1000")
        self.assertEqual(command[command.index("--desktop-targets") + 1], "north-desk")
        self.assertNotIn("--desktop-all", command)

    def test_quota_bucket_is_shared_across_area_queries(self):
        with tempfile.TemporaryDirectory(prefix="sls-xweather-quota-") as directory:
            root = Path(directory)
            status = root / "status.json"
            quota = root / "quota.json"
            status.write_text(json.dumps({
                "xweather_rate_limit_period": 15000,
                "xweather_rate_remaining_period": 14990,
                "xweather_rate_reset_period": "Thu, 17 Sep 2026 00:00:00 GMT",
                "xweather_last_query_cost_tokens": 10,
            }), encoding="utf-8")
            now = 1_787_000_000
            with mock.patch.object(worker, "STATUS_FILE", status), mock.patch.object(worker, "QUOTA_STATE_FILE", quota):
                first = worker.reserve_shared_quota(now)
                first_bucket = json.loads(quota.read_text(encoding="utf-8"))["quota_bucket_tokens"]
                second = worker.reserve_shared_quota(now)
                second_bucket = json.loads(quota.read_text(encoding="utf-8"))["quota_bucket_tokens"]
            self.assertTrue(first[0] and second[0])
            self.assertEqual(round(first_bucket - second_bucket, 4), 10.0)


if __name__ == "__main__":
    unittest.main()
