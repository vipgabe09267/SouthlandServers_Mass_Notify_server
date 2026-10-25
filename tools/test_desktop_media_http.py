#!/usr/bin/python3
"""Actual private HTTPS media authorization; no production HTTP requests."""
import ast
import base64
import hashlib
import hmac
import http.client
import re
import signal
import time
import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "media_http_fixture", ROOT / "tools/test_desktop_http_fleet.py"
)
fixture_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture_module)


class DesktopMediaHttp(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = fixture_module.DesktopHttpFleetTests
        cls.fixture.setUpClass()
        cls.original = json.loads(
            (fixture_module.DATA / "mass-notifications.config").read_text()
        )
        cls.directory = cls.fixture.root / "media"
        cls.directory.mkdir(mode=0o755)
        cls.directory.chmod(0o755)
        cls.name = "announcement_20261023000000_abcdef_desktop.png"
        cls.png = base64.b64decode(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGxkAAAAASUVORK5CYII="
        )
        image = cls.directory / cls.name
        image.write_bytes(cls.png)
        image.chmod(0o644)
        os.chown(image, cls.fixture.runtime.pw_uid, cls.fixture.runtime.pw_gid)
        subprocess.run(
            ["mount", "--bind", str(cls.directory), "/var/www/html/sls_mass_notify"],
            check=True,
            timeout=5,
        )
        tree = ast.parse(
            (
                ROOT
                / "slsmassnotifyserver/bin/sls_mass_notify/sls_privileged_install.py"
            ).read_text()
        )
        policy = next(
            node.value.value
            for node in tree.body
            if isinstance(node, ast.Assign)
            and isinstance(node.value, ast.Constant)
            and isinstance(node.value.value, str)
            and "<Directory /var/www/html/sls_mass_notify>" in node.value.value
        )
        media_policy = (
            re.search(
                r"<Directory /var/www/html/sls_mass_notify>.*?</Directory>",
                policy,
                re.S,
            )
            .group(0)
            .replace("/var/www/html/sls_mass_notify", '"' + str(cls.directory) + '"')
        )
        config = Path(cls.fixture.process.args[2])
        api = cls.fixture.root / "api"
        with config.open("a") as stream:
            stream.write(
                '\nLoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so\nAlias /sls_mass_notify/ "'
                + str(cls.directory)
                + '/"\nAlias /api/sls-mass-notify/ "'
                + str(api)
                + '/sls-mass-notify/"\n'
                + media_policy
                + "\n"
            )
        subprocess.run(
            ["/usr/sbin/apache2", "-t", "-f", str(config)],
            check=True,
            capture_output=True,
            timeout=5,
        )
        cls.fixture.process.send_signal(signal.SIGUSR1)
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline:
            try:
                connection = http.client.HTTPSConnection(
                    "localhost",
                    cls.fixture.port,
                    context=cls.fixture.context,
                    timeout=2,
                )
                connection.request("GET", "/sls_mass_notify/" + cls.name)
                response = connection.getresponse()
                response.read()
                connection.close()
                if response.status == 200:
                    break
            except OSError:
                pass
            time.sleep(0.05)
        else:
            raise RuntimeError("Private Apache media rewrite did not become ready.")

    @classmethod
    def tearDownClass(cls):
        subprocess.run(
            ["umount", "/var/www/html/sls_mass_notify"], check=True, timeout=5
        )
        cls.fixture.tearDownClass()

    def setUp(self):
        self.settings = dict(
            self.original, media_access={"desktop_authentication": True}
        )
        self.save()
        self.event = self.fixture.event("private-image", ["fixture-0"])
        self.event["image_url"] = (
            f"https://localhost:{self.fixture.port}/sls_mass_notify/{self.name}"
        )
        self.fixture.events([self.event])

    def save(self):
        self.fixture.write_state(
            "mass-notifications.config", json.dumps(self.settings).encode()
        )

    def media(self, client=0):
        connection, response = self.fixture.request(
            client, "/api/sipnotify/desktop/media?event_id=private-image", raw=True
        )
        try:
            return response.status, response.read(), dict(response.headers)
        finally:
            connection.close()

    def test_original_recipient_and_forwarded_origin(self):
        status, body, _, _ = self.fixture.request(
            0, "/api/sipnotify/desktop?last_event_id=%40sls%3Aempty"
        )
        self.assertEqual(status, 200, body)
        event = body["events"][0]
        self.assertEqual(event["image_authentication"], "desktop_basic")
        self.assertEqual(
            event["image_url"],
            f"https://localhost:{self.fixture.port}/api/sipnotify/desktop/media?event_id=private-image",
        )
        status, body, headers = self.media()
        self.assertEqual(status, 200, body)
        self.assertEqual(body, self.png)
        self.assertEqual(headers["Content-Type"], "image/png")
        self.assertIn("no-store", headers["Cache-Control"])
        self.assertEqual(self.media(1)[0], 404)

    def test_expiry_replaced_enrollment_and_revocation(self):
        self.event["created_at"] = (
            dt.datetime.now(dt.timezone.utc) - dt.timedelta(seconds=601)
        ).isoformat()
        self.fixture.events([self.event])
        self.assertEqual(self.media()[0], 404)
        self.event["created_at"] = dt.datetime.now(dt.timezone.utc).isoformat()
        self.fixture.events([self.event])
        self.settings["desktop_clients"] = [
            (
                dict(row, client_id="replacement")
                if row["username"] == "fixture-0"
                else row
            )
            for row in self.original["desktop_clients"]
        ]
        self.save()
        self.assertEqual(self.media()[0], 404)
        self.settings["desktop_clients"][0]["enabled"] = "0"
        self.save()
        self.assertEqual(self.media()[0], 401)

    def test_phone_capability_survives_actual_apache_rewrite_and_revocation(self):
        self.settings["media_access"] = {
            "desktop_authentication": True,
            "phone_capabilities": True,
            "phone_capability_generation": "0000000000000000",
        }
        self.save()
        expiry = str(int(time.time()) + 60)
        generation = "0000000000000000"
        signature = hmac.new(
            base64.b64decode(self.settings["desktop_auth_key"]),
            (
                "sls-phone-media-v2\n" + self.name + "\n" + expiry + "\n" + generation
            ).encode(),
            hashlib.sha256,
        ).hexdigest()
        path = (
            "/sls_mass_notify/"
            + self.name
            + "?sls_exp="
            + expiry
            + "&sls_sig="
            + signature
            + "&sls_gen="
            + generation
        )

        def get(url):
            connection = http.client.HTTPSConnection(
                "localhost", self.fixture.port, context=self.fixture.context, timeout=5
            )
            try:
                connection.request("GET", url)
                response = connection.getresponse()
                return response.status, response.read()
            finally:
                connection.close()

        self.assertEqual(get(path), (200, self.png))
        self.assertEqual(get("/sls_mass_notify/" + self.name)[0], 403)
        self.settings["media_access"][
            "phone_capability_generation"
        ] = "1111111111111111"
        self.save()
        self.assertEqual(get(path)[0], 403)
        self.assertEqual(get(path + "&file=other.png")[0], 403)

    def test_foreign_and_unmanaged_images_are_not_proxy_targets(self):
        for image in [
            "https://foreign.invalid/sls_mass_notify/" + self.name,
            "https://localhost:" + str(self.fixture.port) + "/etc/passwd",
            "https://user@localhost/sls_mass_notify/" + self.name,
        ]:
            self.event["image_url"] = image
            self.fixture.events([self.event])
            self.assertEqual(self.media()[0], 404)


if __name__ == "__main__":
    unittest.main()
