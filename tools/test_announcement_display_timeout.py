#!/usr/bin/env python3
"""Focused regressions for regular-announcement display expiry plumbing."""

import configparser
import importlib.util
import re
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SENDER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
DESKTOP_API = ROOT / "slsmassnotifyserver/api/sipnotify/index.php"
SPEC = importlib.util.spec_from_file_location("sls_notify_timeout", SENDER)
SENDER_MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(SENDER_MODULE)


def visual_config():
    config = configparser.ConfigParser(interpolation=None)
    config.read_dict({"visual": {"image_width": "480", "image_height": "272"}})
    return config


class AnnouncementDisplayTimeoutTests(unittest.TestCase):
    def test_timeout_is_clamped(self):
        self.assertEqual(SENDER_MODULE.normalize_announcement_timeout_seconds(-1), 0)
        self.assertEqual(SENDER_MODULE.normalize_announcement_timeout_seconds("90"), 90)
        self.assertEqual(SENDER_MODULE.normalize_announcement_timeout_seconds(999999), 86400)
        self.assertEqual(SENDER_MODULE.normalize_announcement_timeout_seconds("invalid"), 0)

    def test_regular_yealink_text_timeout_defaults_to_no_expiry(self):
        self.assertIn("Timeout='0'", SENDER_MODULE.build_announcement_xml("Test"))
        self.assertIn("Timeout='45'", SENDER_MODULE.build_announcement_xml("Test", 45))
        self.assertIn("Timeout='86400'", SENDER_MODULE.build_announcement_xml("Test", 999999))

    def test_regular_yealink_image_timeout_is_propagated(self):
        with mock.patch.object(SENDER_MODULE, "render_announcement_image", return_value="http://pbx/announcement.png"):
            payload = SENDER_MODULE.build_announcement_image_xml(
                visual_config(), "Test", timeout_seconds=75
            )
        self.assertIn("YealinkIPPhoneImageScreen", payload)
        self.assertIn("Timeout='75'", payload)

    def test_yealink_text_format_gets_timeout_but_generic_does_not(self):
        yealink = SENDER_MODULE.build_phone_xml_for_format(
            visual_config(), "yealink_text", "announcement", message="Test", timeout_seconds=60
        )
        generic = SENDER_MODULE.build_phone_xml_for_format(
            visual_config(), "generic", "announcement", message="Test", timeout_seconds=60
        )
        self.assertIn("Timeout='60'", yealink)
        self.assertNotIn("Timeout=", generic)

    def test_weather_payload_keeps_its_existing_no_expiry_behavior(self):
        alert = {
            "properties": {
                "event": "Tornado Warning",
                "severity": "Extreme",
                "effective": "2026-07-31T12:00:00Z",
                "expires": "2026-07-31T13:00:00Z",
                "areaDesc": "Test County",
                "description": "Test",
            }
        }
        self.assertIn("Timeout='0'", SENDER_MODULE.build_text_xml(alert))
        self.assertIn(
            "Timeout='0'",
            SENDER_MODULE.build_image_xml(visual_config(), alert, "http://pbx/weather.png"),
        )

    def test_desktop_record_contains_utc_expiry_metadata(self):
        record = SENDER_MODULE.announcement_api_record(
            "announcement-test", "Test", SENDER_MODULE.build_announcement_xml("Test", 90), [], timeout_seconds=90
        )
        self.assertEqual(record["display_timeout_seconds"], 90)
        self.assertRegex(record["display_expires_at"], r"Z$")
        expires = datetime.fromisoformat(record["display_expires_at"].replace("Z", "+00:00"))
        self.assertEqual(expires.tzinfo, timezone.utc)

        persistent = SENDER_MODULE.announcement_api_record(
            "announcement-persistent", "Test", SENDER_MODULE.build_announcement_xml("Test"), []
        )
        self.assertEqual(persistent["display_timeout_seconds"], 0)
        self.assertIsNone(persistent["display_expires_at"])

    def test_desktop_api_filters_without_removing_retained_journal_entries(self):
        source = DESKTOP_API.read_text(encoding="utf-8")
        self.assertIn("function announcement_display_expired", source)
        self.assertGreaterEqual(source.count("announcement_display_expired($event"), 2)
        retained = re.search(
            r"function retained_events\(array \$settings\): array\s*\{(?P<body>.*?)\n\}",
            source,
            flags=re.S,
        )
        self.assertIsNotNone(retained)
        self.assertNotIn("announcement_display_expired", retained.group("body"))


if __name__ == "__main__":
    unittest.main()
