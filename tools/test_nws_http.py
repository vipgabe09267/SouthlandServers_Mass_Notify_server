#!/usr/bin/python3
import base64
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import zlib

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "nws_http_fixture", ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_nws_http.py"
)
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
EMPTY = b'{"type":"FeatureCollection","features":[]}'


class NwsHttpTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-nws-http-")
        self.directory = Path(self.temporary.name)
        self.tick = 0
        self.calls = []
        self.wall_base = 1800000000

    def tearDown(self):
        self.temporary.cleanup()

    def sleep(self, seconds):
        self.tick += seconds

    def fetch(self, transport, **options):
        return http.fetch(
            "TXC491",
            directory=self.directory,
            transport=transport,
            clock=lambda: self.tick,
            wall=lambda: self.wall_base + self.tick,
            sleep=self.sleep,
            jitter=lambda low, high: low,
            **options
        )

    def test_304_reuses_exact_validated_bytes_and_sends_only_safe_conditionals(self):
        first = Mock(
            return_value=(
                200,
                {
                    "etag": '"version-one"',
                    "last-modified": "Fri, 15 Jan 2027 08:00:00 GMT",
                },
                EMPTY,
            )
        )
        self.assertEqual(self.fetch(first), EMPTY)
        self.tick = 60
        second = Mock(return_value=(304, {}, b""))
        self.assertEqual(self.fetch(second), EMPTY)
        self.assertEqual(
            second.call_args.args[1],
            {
                "If-None-Match": '"version-one"',
                "If-Modified-Since": "Fri, 15 Jan 2027 08:00:00 GMT",
            },
        )

    def test_503_retry_uses_ipv4_jitter_and_one_cumulative_deadline(self):
        def transport(zone, headers, seconds, ipv4):
            self.calls.append((seconds, ipv4))
            self.tick += 1
            return (
                (503, {"retry-after": "2"}, b"")
                if len(self.calls) == 1
                else (200, {}, EMPTY)
            )

        self.assertEqual(self.fetch(transport), EMPTY)
        self.assertEqual(self.calls, [(12, False), (12, True)])
        self.assertAlmostEqual(self.tick, 4.1)

    def test_429_backoff_is_preserved_across_polls_without_delivering_cached_alerts(
        self,
    ):
        transport = Mock(return_value=(429, {"retry-after": "3600"}, b""))
        with self.assertRaisesRegex(OSError, "HTTP 429"):
            self.fetch(transport)
        again = Mock(return_value=(200, {}, EMPTY))
        with self.assertRaisesRegex(OSError, "requested backoff"):
            self.fetch(again)
        self.assertFalse(again.called)
        self.assertEqual(transport.call_count, 1)
        self.tick = 3601
        self.assertEqual(self.fetch(again), EMPTY)

    def test_slow_failures_cannot_multiply_the_fetch_budget_or_return_stale_cache(self):
        self.fetch(Mock(return_value=(200, {"etag": '"old"'}, EMPTY)))

        def transport(zone, headers, seconds, ipv4):
            self.tick += seconds
            self.calls.append((seconds, ipv4))
            raise OSError("Weather.gov DNS resolution timed out")

        with self.assertRaisesRegex(OSError, "no stale cache"):
            self.fetch(transport)
        self.assertLessEqual(self.tick, 25)
        self.assertLessEqual(len(self.calls), 3)

    def test_expiry_and_cancellation_fields_are_not_renewed_by_304_or_clock_changes(
        self,
    ):
        payload = {
            "type": "FeatureCollection",
            "features": [
                {
                    "id": "urn:uuid:original",
                    "properties": {
                        "event": "Tornado Warning",
                        "status": "Actual",
                        "messageType": "Alert",
                        "expires": "2027-01-15T07:59:00+00:00",
                    },
                },
                {
                    "id": "urn:uuid:cancel",
                    "properties": {"status": "Actual", "messageType": "Cancel"},
                },
            ],
        }
        raw = json.dumps(payload).encode()
        self.fetch(Mock(return_value=(200, {"etag": '"same"'}, raw)))
        self.wall_base -= 3600
        self.assertEqual(self.fetch(Mock(return_value=(304, {}, b""))), raw)
        self.assertEqual(
            json.loads(raw)["features"][0]["properties"]["expires"],
            "2027-01-15T07:59:00+00:00",
        )

    def test_invalid_or_unsafe_cache_does_not_block_fresh_official_data(self):
        path = self.directory / "weather-http-cache.json"
        path.write_text("{bad json")
        path.chmod(0o640)
        transport = Mock(return_value=(200, {}, EMPTY))
        self.assertEqual(self.fetch(transport), EMPTY)
        self.assertEqual(transport.call_args.args[1], {})
        self.assertEqual(path.read_text(), "{bad json")

    def test_malformed_200_is_not_cached_as_an_empty_observation(self):
        transport = Mock(return_value=(200, {}, b'{"error":"upstream failure"}'))
        with self.assertRaises(ValueError):
            self.fetch(transport)
        self.assertFalse((self.directory / "weather-http-cache.json").exists())

    def test_compression_bombs_and_tail_members_are_rejected(self):
        compressed = zlib.compress(b"x" * (http.MAX_BODY + 1))
        record = {
            "bytes": http.MAX_BODY,
            "sha256": "a" * 64,
            "zlib_base64": base64.b64encode(compressed).decode(),
        }
        with self.assertRaises(ValueError):
            http.cached_body(record)
        compressed = zlib.compress(EMPTY) + zlib.compress(EMPTY)
        record["zlib_base64"] = base64.b64encode(compressed).decode()
        with self.assertRaises(ValueError):
            http.cached_body(record)

    def test_transport_disables_curlrc_preserves_dns_failover_and_hides_response_errors(
        self,
    ):
        for code, word in [(6, "DNS"), (60, "TLS"), (28, "timed out")]:
            with patch.object(
                http.subprocess,
                "run",
                return_value=Mock(returncode=code, stdout=b"000", stderr=b"SECRET"),
            ) as run:
                with self.assertRaises(OSError) as error:
                    http.curl_get("TXC491", {}, 12, False)
                self.assertIn(word, str(error.exception))
                self.assertNotIn("SECRET", str(error.exception))
                args = run.call_args.args[0]
                self.assertEqual(args[args.index("/usr/bin/curl") + 1], "-q")
                self.assertGreater(float(args[args.index("--connect-timeout") + 1]), 5)
                self.assertNotIn("preexec_fn", run.call_args.kwargs)
                self.assertEqual(args[0], "/usr/bin/prlimit")
                self.assertEqual(args[args.index("--max-redirs") + 1], "0")
                self.assertNotIn("--insecure", args)


if __name__ == "__main__":
    unittest.main()
