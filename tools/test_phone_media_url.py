#!/usr/bin/env python3
"""Isolated phone-media URL regressions; no PBX writes or notification sends."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import sys

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location(
    "sls_notify_media_test", Path(__file__).resolve().parents[1] / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
)
notify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(notify)


class PhoneMediaUrlTests(unittest.TestCase):
    host = "pbx.example.test"

    def url(self, scheme="https", override=""):
        return notify.phone_media_base_url(self.host, scheme, override)

    def test_legacy_default_urls_unchanged(self):
        for scheme in ("http", "https"):
            for override in ("", None, {}, [], 8443,
                             f"{scheme}://{self.host}/sls_mass_notify",
                             f"{scheme}://{self.host}/sls_mass_notify/"):
                with self.subTest(scheme=scheme, override=override):
                    self.assertEqual(self.url(scheme, override),
                                     f"{scheme}://{self.host}/sls_mass_notify")

    def test_explicit_standard_and_custom_ports(self):
        for scheme, ports in (("http", (80, 8080)), ("https", (443, 8443)),
                              ("https", (1, 65535))):
            for port in ports:
                with self.subTest(scheme=scheme, port=port):
                    expected = f"{scheme}://{self.host}:{port}/sls_mass_notify"
                    self.assertEqual(self.url(scheme, expected), expected)

    def test_case_insensitive_matching_and_fixed_output(self):
        self.assertEqual(self.url(override="HTTPS://PBX.EXAMPLE.TEST:8443/SLS_MASS_NOTIFY/"),
                         "https://pbx.example.test:8443/sls_mass_notify")

    def test_selected_scheme_remains_authoritative(self):
        self.assertEqual(self.url("https", "http://pbx.example.test:8443/sls_mass_notify"),
                         "https://pbx.example.test:8443/sls_mass_notify")
        self.assertEqual(self.url("http", "https://pbx.example.test:8080/sls_mass_notify"),
                         "http://pbx.example.test:8080/sls_mass_notify")

    def test_all_port_values_in_supported_range(self):
        for port in range(1, 65536):
            expected = f"https://{self.host}:{port}/sls_mass_notify"
            self.assertEqual(self.url(override=expected), expected)

    def test_invalid_or_mismatched_overrides_keep_safe_default(self):
        bad_urls = [
            "https://pbx.example.test:0/sls_mass_notify",
            "https://pbx.example.test:65536/sls_mass_notify",
            "https://pbx.example.test:100000/sls_mass_notify",
            "https://pbx.example.test:-1/sls_mass_notify",
            "https://pbx.example.test:+443/sls_mass_notify",
            "https://pbx.example.test:abc/sls_mass_notify",
            "https://pbx.example.test:/sls_mass_notify",
            "https://pbx.example.test:8443/other",
            "https://pbx.example.test:8443",
            "https://pbx.example.test:8443/sls_mass_notify?x=1",
            "https://pbx.example.test:8443/sls_mass_notify#x",
            "https://user:secret@pbx.example.test:8443/sls_mass_notify",
            "https://pbx.example.test@other.test:8443/sls_mass_notify",
            "https://other.test:8443/sls_mass_notify",
            "https://pbxXexampleXtest:8443/sls_mass_notify",
            "https://pbx.example.test.attacker.test:8443/sls_mass_notify",
            "//pbx.example.test:8443/sls_mass_notify",
            "ftp://pbx.example.test:8443/sls_mass_notify",
            " https://pbx.example.test:8443/sls_mass_notify",
            "https://pbx.example.test:8443/sls_mass_notify\n",
            "https://pbx.example.test:8443/sls_mass_notify\r\nX: injected",
            "https://pbx.example.test:8443/sls_mass_notify\x00",
            "https://pbx.example.test:８４４３/sls_mass_notify",
            "https://pbx.example.teſt:8443/sls_mass_notify",
        ]
        for override in bad_urls:
            with self.subTest(override=override):
                self.assertEqual(self.url(override=override),
                                 "https://pbx.example.test/sls_mass_notify")

    def test_load_config_uses_only_media_override_port(self):
        settings = {
            "public_pbx_host": self.host,
            "sipnotify": {"media_scheme": "https",
                          "media_base_url": f"https://{self.host}:8443/sls_mass_notify"},
        }
        with tempfile.TemporaryDirectory(prefix="sls-media-url-test-") as directory:
            config_path = Path(directory) / "fixture.config"
            config_path.write_text(json.dumps(settings), encoding="utf-8")
            with patch.object(notify, "CENTRAL_SETTINGS_FILE", config_path), \
                 patch.object(notify, "resolve_local_ami_endpoint", return_value=("127.0.0.1", 5038)):
                result = notify.load_config()
        self.assertEqual(result.get("visual", "public_base_url"),
                         f"https://{self.host}:8443/sls_mass_notify")


if __name__ == "__main__":
    unittest.main()
