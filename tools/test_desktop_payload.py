#!/usr/bin/env python3
"""Desktop metadata/image checks with temporary files and inert delivery adapters."""
import configparser
import importlib.util
import json
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
spec = importlib.util.spec_from_file_location("desktop_payload_notify", RUNTIME / "sls_notify.py")
notify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(notify)


def config(directory="/unused"):
    value = configparser.ConfigParser(interpolation=None)
    value.read_dict({"visual": {"web_dir": str(directory), "image_width": "480", "image_height": "272",
        "public_base_url": "http://pbx.example.test:8080/sls_mass_notify",
        "desktop_public_base_url": "https://pbx.example.test:8443/sls_mass_notify"},
        "logging": {"log_file": str(Path(directory) / "unused.log")}})
    return value


class DesktopPayloadTests(unittest.TestCase):
    def test_only_explicit_boolean_marks_a_test(self):
        for flag in (False, None, "false", "true", 1, True):
            weather = notify.alert_api_record({"id": "live-test-wording", "is_test": True,
                "properties": {"event": "Test weather wording", "severity": "Extreme"}}, "", [], is_test=flag)
            announcement = notify.announcement_api_record("test-name", "This says test and all clear", "", [], is_test=flag)
            self.assertIs(weather["is_test"], flag is True)
            self.assertIs(announcement["is_test"], flag is True)

    def test_manual_weather_cli_is_test_but_live_json_is_not(self):
        import base64
        encoded = base64.b64encode(json.dumps({"id": "test-wording", "is_test": True,
            "properties": {"event": "Test wording"}}).encode()).decode()
        for args, expected in ((["--event", "Tornado Warning"], True),
                               (["--alert-json-b64", encoded], False)):
            with mock.patch.object(sys, "argv", ["sls_notify.py", *args]), \
                    mock.patch.object(notify, "load_config", return_value=config()), \
                    mock.patch.object(notify, "setup_logging"), mock.patch.object(notify, "push_alert") as push:
                self.assertEqual(notify.main(), 0)
                self.assertIs(push.call_args.kwargs["is_test"], expected)
        with mock.patch.object(notify, "push_alert") as push:
            notify.run_test(config())
            self.assertIs(push.call_args.kwargs["is_test"], True)

    def test_announcement_cli_requires_explicit_flag(self):
        for extra, expected in (([], False), (["--is-test"], True)):
            with mock.patch.object(sys, "argv", ["sls_notify.py", "--announcement", "TEST wording", *extra]), \
                    mock.patch.object(notify, "load_config", return_value=config()), \
                    mock.patch.object(notify, "setup_logging"), mock.patch.object(notify, "push_announcement") as push:
                self.assertEqual(notify.main(), 0)
                self.assertIs(push.call_args.kwargs["is_test"], expected)

    def test_desktop_url_transport_never_changes_phone_media(self):
        for invalid in ("http://pbx.example.test:8080/api/sipnotify", "https://other.example:8443/api/sipnotify",
                        "https://pbx.example.test:0/api/sipnotify", "https://pbx.example.test:65536/api/sipnotify",
                        "https://user@pbx.example.test:8443/api/sipnotify", "https://pbx.example.test:8443/api/sipnotify\n"):
            self.assertEqual(notify.desktop_media_base_url("pbx.example.test", invalid),
                             "https://pbx.example.test/sls_mass_notify")
        self.assertEqual(notify.desktop_media_base_url("pbx.example.test", "https://pbx.example.test:8443/api/sipnotify"),
                         "https://pbx.example.test:8443/sls_mass_notify")
        self.assertEqual(notify.phone_media_base_url("pbx.example.test", "http", "http://pbx.example.test:8080/sls_mass_notify"),
                         "http://pbx.example.test:8080/sls_mass_notify")

    def test_actual_images_are_distinct_sharp_bounded_and_public(self):
        with tempfile.TemporaryDirectory(prefix="sls-desktop-images-") as directory:
            settings = config(directory)
            phone = notify.render_announcement_image(settings, "TEST", "A bounded fixture message.", "#123456")
            desktop = notify.render_announcement_image(settings, "TEST", "A bounded fixture message.", "#123456", desktop=True)
            self.assertTrue(phone.startswith("http://pbx.example.test:8080/"))
            self.assertTrue(desktop.startswith("https://pbx.example.test:8443/"))
            self.assertNotEqual(phone.rsplit("/", 1)[1], desktop.rsplit("/", 1)[1])
            for url, dimensions in ((phone, (480, 272)), (desktop, (1440, 816))):
                path = Path(directory) / url.rsplit("/", 1)[1]
                self.assertEqual(struct.unpack(">II", path.read_bytes()[16:24]), dimensions)
                self.assertEqual(path.stat().st_mode & 0o777, 0o644)
                self.assertLessEqual(path.stat().st_size, 5 * 1024 * 1024)

    def test_oversized_desktop_image_is_removed(self):
        with tempfile.TemporaryDirectory(prefix="sls-desktop-oversize-") as directory:
            def render(command, **kwargs):
                with Path(command[-1].removeprefix("PNG24:")).open("wb") as handle:
                    handle.truncate(5 * 1024 * 1024 + 1)
                return subprocess.CompletedProcess(command, 0, stderr="")
            with mock.patch.object(notify.subprocess, "run", side_effect=render), \
                    mock.patch.object(notify, "validate_rendered_png"):
                with self.assertRaisesRegex(RuntimeError, "5 MiB"):
                    notify.render_announcement_image(config(directory), "Fixture", "Message", desktop=True)
            self.assertEqual(list(Path(directory).iterdir()), [])

    def test_desktop_publication_retains_plain_text_and_phone_xml(self):
        phone_xml = notify.yealink_image_xml("http://pbx.example.test:8080/sls_mass_notify/phone.png")
        desktop_url = "https://pbx.example.test:8443/sls_mass_notify/desktop.png"
        with mock.patch.object(notify, "build_announcement_image_xml", return_value=phone_xml), \
                mock.patch.object(notify, "render_announcement_image", return_value=desktop_url), \
                mock.patch.object(notify, "append_sipnotify_event") as append:
            notify.push_announcement(config(), "Full meaningful text", [], api_only=True, image=True,
                desktop_targets=["fixture"], is_test=True, print_results=False)
        record = append.call_args.args[1]
        self.assertEqual(record["image_url"], desktop_url)
        self.assertEqual(record["phone_image_url"], "http://pbx.example.test:8080/sls_mass_notify/phone.png")
        self.assertEqual(record["xml"], phone_xml)
        self.assertEqual(record["description"], "Full meaningful text")
        self.assertIs(record["is_test"], True)

    def test_failed_desktop_rendition_preserves_complete_plain_text(self):
        phone_xml = notify.yealink_image_xml("http://pbx.example.test/sls_mass_notify/phone.png")
        with mock.patch.object(notify, "build_announcement_image_xml", return_value=phone_xml), \
                mock.patch.object(notify, "render_announcement_image", side_effect=RuntimeError("bounded fixture failure")), \
                mock.patch.object(notify, "append_sipnotify_event") as append, self.assertLogs(level="ERROR"):
            notify.push_announcement(config(), "Full meaningful text", [], api_only=True, image=True,
                desktop_targets=["fixture"], print_results=False)
        self.assertEqual(append.call_args.args[1]["image_url"], "")
        self.assertEqual(append.call_args.args[1]["description"], "Full meaningful text")

    def public_payload(self, settings, event, server):
        source = (ROOT / "slsmassnotifyserver/api/sipnotify/index.php").read_text()
        function = source.split("function desktop_event_public_payload", 1)[1].split("function desktop_stream_events_after_cursor", 1)[0]
        with tempfile.TemporaryDirectory(prefix="sls-desktop-public-payload-") as directory:
            payload = Path(directory) / "input.json"
            payload.write_text(json.dumps([settings, event, server]))
            harness = Path(directory) / "check.php"
            harness.write_text("<?php\nfunction desktop_event_public_payload" + function
                + "\n[$settings,$event,$_SERVER]=json_decode(file_get_contents($argv[1]),true);"
                + "echo json_encode(desktop_event_public_payload($event,$settings));")
            result = subprocess.run(["php", str(harness), str(payload)], text=True, capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stderr, "")
            return json.loads(result.stdout)

    def test_api_uses_actual_external_https_port_only_for_configured_host(self):
        path = "/sls_mass_notify/announcement_20260920235959_" + "a" * 24 + ".png"
        settings = {"public_pbx_host": "pbx.example.test", "sipnotify": {"base_url": "https://pbx.example.test:9443/api/sipnotify"}}
        event = {"image_url": "http://pbx.example.test:8080" + path, "xml": "phone unchanged", "description": "Full text"}
        for host in ("pbx.example.test:8443", "PBX.EXAMPLE.TEST:8443"):
            result = self.public_payload(settings, event, {"HTTP_HOST": host, "SERVER_PORT": "443", "HTTPS": "on"})
            self.assertEqual(result, {**event, "image_url": "https://pbx.example.test:8443" + path, "is_test": False})
        for host in ("attacker.test:8443", "pbx.example.test:8443\r\nHost: attacker.test", "pbx.example.test:0",
                     "pbx.example.test:65536", "pbx.example.test:8443@attacker.test", ["pbx.example.test:8443"]):
            result = self.public_payload(settings, event, {"HTTP_HOST": host, "HTTP_X_FORWARDED_HOST": "attacker.test:9999"})
            self.assertEqual(result["image_url"], "https://pbx.example.test:9443" + path)

    def test_api_does_not_rewrite_foreign_or_unsafe_image_urls(self):
        settings = {"public_pbx_host": "pbx.example.test"}
        for url in ("https://attacker.test/sls_mass_notify/announcement_20260920235959_abcdef.png",
                    "https://pbx.example.test/sls_mass_notify/../private.png",
                    "https://pbx.example.test/sls_mass_notify/announcement_20260920235959_abcdef.png?secret=x",
                    "https://pbx.example.test:0/sls_mass_notify/announcement_20260920235959_abcdef.png",
                    "https://pbx.example.test:65536/sls_mass_notify/announcement_20260920235959_abcdef.png",
                    "https://pbx.example.test/sls_mass_notify/announcement_20260920235959_abcdef.png\n"):
            event = {"image_url": url, "description": "Preserved"}
            self.assertEqual(self.public_payload(settings, event, {"HTTP_HOST": "pbx.example.test:8443"}), {**event, "is_test": False})

    def test_legacy_public_event_flags_preserve_identity_dates_and_incident_state(self):
        original = {"id": "stable-legacy-event", "schema_version": 1, "kind": "announcement",
                    "created_at": "2026-07-11T09:35:00+00:00", "expires_at": "2026-07-11T09:45:00+00:00",
                    "title": "Test all-clear wording", "description": "Meaningful fallback text",
                    "incident_id": "incident-fixed", "incident_revision": 3, "incident_state": "open"}
        for settings in ({}, {"public_pbx_host": "pbx.example.test"}):
            result = self.public_payload(settings, original, {})
            self.assertEqual(result, {**original, "is_test": False})
            for flag in (False, None, "true", "false", 1, [], True):
                event = {**original, "is_test": flag}
                result = self.public_payload(settings, event, {})
                self.assertEqual(result, {**original, "is_test": flag is True})


if __name__ == "__main__":
    unittest.main()
