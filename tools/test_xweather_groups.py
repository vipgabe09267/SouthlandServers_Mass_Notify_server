#!/usr/bin/env python3
"""Focused Lightning trigger-area dispatcher, routing, and quota regressions."""

import importlib.util
import json
import os
import sys
import tempfile
import unittest
from contextlib import ExitStack
from datetime import datetime, timezone
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
        delivery_order = []

        def run_visual(command, **_kwargs):
            delivery_order.append("desktop" if "--api-only" in command else "phone")

        with mock.patch.object(worker.subprocess, "run", side_effect=run_visual) as run, \
                mock.patch.object(worker.time, "sleep", side_effect=lambda seconds: delivery_order.append(f"sleep:{seconds:g}")):
            worker.send_visual(
                ["1000"], ["north-desk"], "Test", is_test=True, phone_delay_seconds=2
            )
        self.assertEqual(run.call_count, 2)
        desktop_command = run.call_args_list[0].args[0]
        phone_command = run.call_args_list[1].args[0]
        self.assertEqual(delivery_order, ["desktop", "sleep:2", "phone"])
        self.assertEqual(phone_command[phone_command.index("--targets") + 1], "1000")
        self.assertIn("--no-api", phone_command)
        self.assertNotIn("--desktop-targets", phone_command)
        self.assertEqual(desktop_command[desktop_command.index("--desktop-targets") + 1], "north-desk")
        self.assertIn("--api-only", desktop_command)
        self.assertNotIn("--targets", desktop_command)
        self.assertNotIn("--desktop-all", desktop_command)

    def test_visual_channel_failures_do_not_suppress_the_other_channel(self):
        desktop_failure = worker.subprocess.CalledProcessError(1, ["desktop"])
        with mock.patch.object(
            worker.subprocess, "run", side_effect=[desktop_failure, None]
        ) as run, mock.patch.object(worker.time, "sleep"):
            with self.assertRaisesRegex(RuntimeError, "desktop journal publication failed"):
                worker.send_visual(
                    ["1000"], ["north-desk"], "Test", phone_delay_seconds=2
                )
        self.assertEqual(run.call_count, 2, "desktop failure suppressed phone SIP NOTIFY")
        self.assertIn("--api-only", run.call_args_list[0].args[0])
        self.assertIn("--no-api", run.call_args_list[1].args[0])

        phone_failure = worker.subprocess.CalledProcessError(1, ["phone"])
        with mock.patch.object(
            worker.subprocess, "run", side_effect=[None, phone_failure]
        ) as run, mock.patch.object(worker.time, "sleep"):
            with self.assertRaisesRegex(RuntimeError, "phone SIP NOTIFY submission failed"):
                worker.send_visual(
                    ["1000"], ["north-desk"], "Test", phone_delay_seconds=2
                )
        self.assertEqual(run.call_count, 2, "phone failure occurred before desktop publication")
        self.assertIn("--api-only", run.call_args_list[0].args[0])

    def test_strike_type_filters_are_per_area_and_defensively_enforced(self):
        payload = {
            "success": True,
            "response": [
                {"id": "ground", "ob": {"timestamp": 1_800_000_000, "pulse": {"type": "cg"}}},
                {"id": "cloud", "ob": {"timestamp": 1_800_000_000, "pulse": {"type": "ic"}}},
            ],
        }
        with mock.patch.object(worker.time, "time", return_value=1_800_000_030):
            self.assertEqual([row["id"] for row in worker.normalize_records(payload)], ["ground"])
            self.assertEqual(
                [row["id"] for row in worker.normalize_records(payload, "cloud_to_cloud")],
                ["cloud"],
            )
            self.assertEqual(
                [row["id"] for row in worker.normalize_records(payload, "both")],
                ["ground", "cloud"],
            )

    def test_structured_forecast_uses_probability_and_weather_fields(self):
        now = 1_800_000_000
        valid_time = "2027-01-15T08:00:00+00:00/PT3H"
        with mock.patch.object(worker, "NWS_THUNDER_PROBABILITY_THRESHOLD", 15):
            active, _message, probability, _transition = worker._forecast_indicates_thunder(
                {
                    "properties": {
                        "probabilityOfThunder": {"values": [{"validTime": valid_time, "value": 30}]},
                        "weather": {"values": []},
                    }
                },
                now,
                3 * 3600,
            )
            self.assertTrue(active)
            self.assertEqual(probability, 30.0)
            active, _message, probability, _transition = worker._forecast_indicates_thunder(
                {
                    "properties": {
                        "probabilityOfThunder": {"values": [{"validTime": valid_time, "value": 0}]},
                        "weather": {
                            "values": [{"validTime": valid_time, "value": [{"weather": "thunderstorms"}]}]
                        },
                    }
                },
                now,
                3 * 3600,
            )
            self.assertTrue(active)
            self.assertEqual(probability, 0.0)

    def test_future_thunder_window_does_not_open_paid_polling_early(self):
        storm_start = datetime(2027, 1, 15, 20, 0, tzinfo=timezone.utc).timestamp()
        before_storm = storm_start - 3600
        payload = {
            "properties": {
                "probabilityOfThunder": {
                    "values": [{"validTime": "2027-01-15T20:00:00+00:00/PT2H", "value": 35}]
                },
                "weather": {
                    "values": [{"validTime": "2027-01-15T20:00:00+00:00/PT2H", "value": [{"weather": "thunderstorms"}]}]
                },
            }
        }
        inactive, message, probability, transition = worker._forecast_indicates_thunder(
            payload, before_storm, 6 * 3600
        )
        self.assertFalse(inactive)
        self.assertIn("current forecast period", message)
        self.assertEqual(probability, 0.0)
        self.assertEqual(transition, int(storm_start))
        active, _message, probability, transition = worker._forecast_indicates_thunder(
            payload, storm_start, 6 * 3600
        )
        self.assertTrue(active)
        self.assertEqual(probability, 35.0)
        self.assertEqual(transition, int(storm_start + 2 * 3600))

    def test_forecast_cache_expires_at_storm_window_boundary(self):
        storm_start = int(datetime(2027, 1, 15, 20, 0, tzinfo=timezone.utc).timestamp())
        payload = {
            "properties": {
                "probabilityOfThunder": {
                    "values": [{"validTime": "2027-01-15T20:00:00+00:00/PT2H", "value": 35}]
                },
                "weather": {"values": []},
            }
        }
        with tempfile.TemporaryDirectory(prefix="sls-xweather-forecast-boundary-") as directory:
            root = Path(directory)
            fixture = root / "forecast.json"
            fixture.write_text(json.dumps(payload), encoding="utf-8")
            cache = root / "nws-forecast-gate-area.json"
            cache.write_text(json.dumps({
                "checked_at": storm_start - 300,
                "expires_at": storm_start,
                "active": False,
                "message": "cached standby",
            }), encoding="utf-8")
            with mock.patch.object(worker, "DATA_DIR", root), \
                    mock.patch.object(worker, "CURRENT_GROUP_ID", "area"), \
                    mock.patch.dict(os.environ, {"NWS_FORECAST_TEST_PAYLOAD": str(fixture)}):
                before, before_message, before_available = worker.forecast_storm_gate(
                    {}, {"location": "30.5000,-97.7000"}, storm_start - 1, 60
                )
                active, _message, available = worker.forecast_storm_gate(
                    {}, {"location": "30.5000,-97.7000"}, storm_start, 60
                )
            self.assertFalse(before)
            self.assertEqual(before_message, "cached standby")
            self.assertTrue(before_available)
            self.assertTrue(active)
            self.assertTrue(available)

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

    def test_external_only_live_area_skips_local_audio_and_visual_cleanly(self):
        xweather = {
            "enabled": "1",
            "client_id": "client",
            "client_secret": "secret",
            "adaptive_free_tier": "0",
            "query_interval_minutes": 5,
            "groups": [{
                "id": "external",
                "name": "External Only",
                "enabled": "1",
                "location": "Round Rock, TX",
                "radius_miles": 10,
                "extensions": [],
                "desktop_clients": [],
                "email_recipients": [],
                "all_clear": "none",
            }],
        }
        config = {
            "desktop_clients": [],
            "generic_webhooks": [{
                "id": "archive",
                "name": "Archive",
                "enabled": "1",
                "url": "https://hooks.example.com/lightning",
            }],
        }
        queued = []
        events = []

        def queue_external(*args, **kwargs):
            queued.append((args, kwargs))
            return "delivery-key"

        with ExitStack() as stack:
            stack.enter_context(mock.patch.object(worker, "load_config", return_value=(config, xweather)))
            stack.enter_context(mock.patch.object(worker, "configure_group_runtime"))
            stack.enter_context(mock.patch.object(worker, "CURRENT_GROUP_ID", "external"))
            stack.enter_context(mock.patch.object(worker, "CURRENT_GROUP_NAME", "External Only"))
            stack.enter_context(mock.patch.object(worker, "CURRENT_GROUP_LEGACY", False))
            stack.enter_context(mock.patch.object(worker, "read_state", return_value={}))
            stack.enter_context(mock.patch.object(worker, "atomic_json_update"))
            stack.enter_context(mock.patch.object(worker, "fetch_payload", return_value={"success": True}))
            stack.enter_context(mock.patch.object(worker, "normalize_records", return_value=[{"id": "strike", "distance_miles": 4.1}]))
            stack.enter_context(mock.patch.object(worker, "queue_external_delivery", side_effect=queue_external))
            stack.enter_context(mock.patch.object(worker, "external_delivery_recorded", return_value=False))
            stack.enter_context(mock.patch.object(worker, "external_delivery_pending", return_value=False))
            stack.enter_context(mock.patch.object(worker, "retry_external_deliveries", return_value={
                    "results": [{"delivery": "delivery-key", "type": "generic", "id": "archive", "status": "accepted"}],
                    "pending": 0,
                }))
            stack.enter_context(mock.patch.object(worker, "append_event", side_effect=events.append))
            record_outcome = stack.enter_context(mock.patch.object(worker, "record_xweather_outcome"))
            generate_audio = stack.enter_context(mock.patch.object(worker, "generate_audio"))
            queue_audio = stack.enter_context(mock.patch.object(worker, "queue_audio"))
            send_visual = stack.enter_context(mock.patch.object(worker, "send_visual"))
            stack.enter_context(mock.patch.object(worker, "log"))
            stack.enter_context(mock.patch.object(worker.time, "time", return_value=1_800_000_000))
            stack.enter_context(mock.patch.dict(worker.os.environ, {}, clear=True))
            self.assertEqual(worker.main(), 0)

        self.assertEqual(len(queued), 1)
        generate_audio.assert_not_called()
        queue_audio.assert_not_called()
        send_visual.assert_not_called()
        self.assertEqual(events[-1]["local_delivery_outcome"], "not_requested")
        self.assertEqual(events[-1]["status"], "queued")
        self.assertIn("no local phone or Desktop channel was requested", record_outcome.call_args.args[2])

    def test_external_only_manual_area_is_rejected_without_external_delivery(self):
        xweather = {
            "enabled": "1",
            "client_id": "client",
            "client_secret": "secret",
            "adaptive_free_tier": "0",
            "groups": [{
                "id": "external",
                "name": "External Only",
                "enabled": "1",
                "location": "Round Rock, TX",
                "radius_miles": 10,
                "extensions": [],
                "desktop_clients": [],
                "email_recipients": ["lightning@example.com"],
                "all_clear": "none",
            }],
        }
        with ExitStack() as stack:
            stack.enter_context(mock.patch.object(worker, "load_config", return_value=({}, xweather)))
            stack.enter_context(mock.patch.object(worker, "configure_group_runtime"))
            queue_external = stack.enter_context(mock.patch.object(worker, "queue_external_delivery"))
            generate_audio = stack.enter_context(mock.patch.object(worker, "generate_audio"))
            queue_audio = stack.enter_context(mock.patch.object(worker, "queue_audio"))
            send_visual = stack.enter_context(mock.patch.object(worker, "send_visual"))
            stack.enter_context(mock.patch.object(worker, "log"))
            stack.enter_context(mock.patch.dict(worker.os.environ, {"XWEATHER_TEST_EVENT": "entry"}, clear=True))
            self.assertEqual(worker.main(), 1)
        queue_external.assert_not_called()
        generate_audio.assert_not_called()
        queue_audio.assert_not_called()
        send_visual.assert_not_called()


if __name__ == "__main__":
    unittest.main()
