#!/usr/bin/env python3
"""Deterministic Xweather account-period snapshot and rollover regressions."""

import importlib.util
import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))
WORKER = RUNTIME / "sls_mass_notify_xweather_poll.py"
SPEC = importlib.util.spec_from_file_location("sls_xweather_usage_period", WORKER)
worker = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(worker)


class XweatherUsagePeriodTests(unittest.TestCase):
    def setUp(self):
        self.original_headers = worker.LAST_RATE_LIMIT

    def tearDown(self):
        worker.LAST_RATE_LIMIT = self.original_headers

    def test_reset_header_parser_is_timezone_and_locale_independent(self):
        expected = int(datetime(2026, 8, 17, tzinfo=timezone.utc).timestamp())
        self.assertEqual(
            worker.parse_rate_reset_epoch("Mon, 17 Aug 2026 00:00:00 GMT"),
            expected,
        )
        self.assertEqual(worker.parse_rate_reset_epoch(str(expected)), expected)
        self.assertEqual(worker.parse_rate_reset_epoch("not a date"), 0)

    def test_complete_period_headers_are_saved_as_one_dated_snapshot(self):
        observed = int(datetime(2026, 8, 12, 15, 30, tzinfo=timezone.utc).timestamp())
        reset = "Mon, 17 Aug 2026 00:00:00 GMT"
        worker.LAST_RATE_LIMIT = {
            "limit": 15000,
            "remaining": 14084,
            "reset_at": reset,
            "cost_tokens": 10,
        }

        patch = worker.rate_limit_status_patch(observed)

        self.assertEqual(patch["xweather_rate_limit_period"], 15000)
        self.assertEqual(patch["xweather_rate_remaining_period"], 14084)
        self.assertEqual(patch["xweather_rate_reset_period"], reset)
        self.assertEqual(
            patch["xweather_rate_reset_epoch"],
            int(datetime(2026, 8, 17, tzinfo=timezone.utc).timestamp()),
        )
        self.assertEqual(patch["xweather_rate_observed_at"], "2026-08-12T15:30:00+00:00")
        self.assertEqual(patch["xweather_last_query_cost_tokens"], 10)

    def test_partial_headers_do_not_mix_two_account_periods(self):
        worker.LAST_RATE_LIMIT = {
            "limit": 15000,
            "reset_at": "Thu, 17 Sep 2026 00:00:00 GMT",
            "cost_tokens": 10,
        }

        patch = worker.rate_limit_status_patch(1_787_000_000)

        self.assertEqual(patch, {"xweather_last_query_cost_tokens": 10})

    def test_expired_low_balance_allows_one_budgeted_refresh_query(self):
        now = int(datetime(2026, 8, 22, tzinfo=timezone.utc).timestamp())
        status = {
            "xweather_rate_limit_period": 15000,
            "xweather_rate_remaining_period": 5,
            "xweather_rate_reset_period": "Mon, 17 Aug 2026 00:00:00 GMT",
            "xweather_last_query_cost_tokens": 10,
        }
        state = {}

        with mock.patch.object(worker, "read_json_object", return_value=status):
            allowed, cost, message = worker.quota_governor(state, now)

        self.assertTrue(allowed)
        self.assertEqual(cost, 10)
        self.assertEqual(message, "")
        self.assertEqual(
            state["quota_reset_marker"],
            f"expired:{int(datetime(2026, 8, 17, tzinfo=timezone.utc).timestamp())}",
        )

    def test_current_zero_balance_still_blocks_queries(self):
        now = int(datetime(2026, 8, 22, tzinfo=timezone.utc).timestamp())
        status = {
            "xweather_rate_limit_period": 15000,
            "xweather_rate_remaining_period": 0,
            "xweather_rate_reset_period": "Thu, 17 Sep 2026 00:00:00 GMT",
            "xweather_last_query_cost_tokens": 10,
        }

        with mock.patch.object(worker, "read_json_object", return_value=status):
            allowed, cost, message = worker.quota_governor({}, now)

        self.assertFalse(allowed)
        self.assertEqual(cost, 10)
        self.assertIn("balance is too low", message)


if __name__ == "__main__":
    unittest.main()
