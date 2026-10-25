#!/usr/bin/python3
"""Real Weather.gov-shaped TLS traffic in the private network namespace."""
from concurrent.futures import ThreadPoolExecutor
import http.server as server_module
import importlib.util
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "nws_wire_source", ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_nws_http.py"
)
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
EMPTY = b'{"type":"FeatureCollection","features":[]}'


class WeatherWire(unittest.TestCase):
    def setUp(self):
        if os.environ.get("SLS_TEST_NAMESPACE") != "entered" or os.environ.get(
            "SLS_TEST_PARENT_NET_NS"
        ) == os.readlink("/proc/self/ns/net"):
            self.fail("Use the private-network fixture runner.")
        subprocess.run(["ip", "link", "set", "lo", "up"], check=True, timeout=5)
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-nws-wire-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.certificate, self.key = self.root / "ca.pem", self.root / "key.pem"
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-sha256",
                "-days",
                "1",
                "-keyout",
                str(self.key),
                "-out",
                str(self.certificate),
                "-subj",
                "/CN=api.weather.gov",
                "-addext",
                "subjectAltName=DNS:api.weather.gov",
                "-addext",
                "basicConstraints=critical,CA:TRUE",
            ],
            check=True,
            capture_output=True,
            timeout=10,
        )
        self.requests = []
        owner = self

        class Handler(server_module.BaseHTTPRequestHandler):
            def do_GET(self):
                owner.requests.append(dict(self.headers))
                not_modified = self.headers.get("If-None-Match") == '"private-one"'
                self.send_response(304 if not_modified else 200)
                self.send_header("ETag", '"private-one"')
                self.send_header(
                    "Content-Length", "0" if not_modified else str(len(EMPTY))
                )
                self.end_headers()
                if not not_modified:
                    self.wfile.write(EMPTY)

            def log_message(self, *_):
                pass

        self.server = server_module.ThreadingHTTPServer(("127.0.0.1", 443), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(self.certificate, self.key)
        self.server.socket = context.wrap_socket(self.server.socket, server_side=True)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop)
        self.trust_ca = True
        original_run = subprocess.run

        def local_curl(command, **kwargs):
            if isinstance(command, list) and "/usr/bin/curl" in command:
                self.assertTrue(
                    command[-1].startswith(
                        "https://api.weather.gov/alerts/active?zone="
                    )
                )
                additions = ["--resolve", "api.weather.gov:443:127.0.0.1"]
                if self.trust_ca:
                    additions += ["--cacert", str(self.certificate)]
                command = command[:-1] + additions + command[-1:]
            return original_run(command, **kwargs)

        self.enterContext(
            mock.patch.object(http.subprocess, "run", side_effect=local_curl)
        )
        self.enterContext(
            mock.patch.dict(
                os.environ,
                {
                    "NO_PROXY": "*",
                    "no_proxy": "*",
                    "HTTPS_PROXY": "",
                    "https_proxy": "",
                    "ALL_PROXY": "",
                    "all_proxy": "",
                },
            )
        )

    def stop(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)

    def test_actual_curl_200_then_304_preserves_bytes(self):
        first = http.fetch("TXC491", directory=self.root)
        second = http.fetch("TXC491", directory=self.root)
        self.assertEqual(first, EMPTY)
        self.assertEqual(second, first)
        self.assertEqual(self.requests[1]["If-None-Match"], '"private-one"')

    def test_parallel_zone_cache_updates_preserve_both_entries(self):
        with ThreadPoolExecutor(max_workers=2) as pool:
            values = list(
                pool.map(
                    lambda zone: http.fetch(zone, directory=self.root),
                    ["TXC491", "TXC453"],
                )
            )
        self.assertEqual(values, [EMPTY, EMPTY])
        state = http.read_json(
            self.root / "weather-http-cache.json",
            maximum=http.MAX_CACHE,
            runtime_owner=True,
        )
        self.assertEqual(set(state["zones"]), {"TXC491", "TXC453"})

    def test_untrusted_certificate_is_never_accepted(self):
        self.trust_ca = False
        with self.assertRaisesRegex(OSError, "TLS verification"):
            http.curl_get("TXC491", {}, 2, False)
        self.assertEqual(self.requests, [])


if __name__ == "__main__":
    unittest.main()
