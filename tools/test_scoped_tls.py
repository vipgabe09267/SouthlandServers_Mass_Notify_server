#!/usr/bin/env python3
"""Real HTTPS connector requests in a private network namespace, with fixture CAs."""
import base64
import hashlib
import http.server
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ScopedTLS(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        parent = os.environ.get("SLS_TEST_PARENT_NET_NS") or os.environ.get(
            "SLS_BUILD_PARENT_NET"
        )
        if not parent or os.readlink("/proc/self/ns/net") == parent:
            raise RuntimeError(
                "Scoped TLS fixtures require the private-network test wrapper."
            )
        cls.temporary = tempfile.TemporaryDirectory(prefix="sls-scoped-tls-")
        cls.root = Path(cls.temporary.name)
        cls.command(["ip", "addr", "add", "10.87.0.1/32", "dev", "lo"])
        cls.command(
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
                "-subj",
                "/CN=SLS fixture CA",
                "-addext",
                "basicConstraints=critical,CA:TRUE",
                "-addext",
                "keyUsage=critical,keyCertSign,cRLSign",
                "-keyout",
                "ca.key",
                "-out",
                "ca.pem",
            ]
        )
        cls.command(
            [
                "openssl",
                "req",
                "-new",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-sha256",
                "-subj",
                "/CN=fixture.test",
                "-keyout",
                "server.key",
                "-out",
                "server.csr",
            ]
        )
        (cls.root / "extensions").write_text(
            "basicConstraints=critical,CA:FALSE\nsubjectAltName=DNS:fixture.test\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n"
        )
        cls.command(
            [
                "openssl",
                "x509",
                "-req",
                "-in",
                "server.csr",
                "-CA",
                "ca.pem",
                "-CAkey",
                "ca.key",
                "-CAcreateserial",
                "-days",
                "1",
                "-sha256",
                "-extfile",
                "extensions",
                "-out",
                "server.pem",
            ]
        )
        public = cls.command(
            ["openssl", "x509", "-in", "server.pem", "-pubkey", "-noout"]
        ).stdout
        der = cls.command(
            ["openssl", "pkey", "-pubin", "-outform", "DER"], input=public
        ).stdout
        cls.pin = "sha256//" + base64.b64encode(hashlib.sha256(der).digest()).decode()
        cls.calls = []

        class Receiver(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def receive(self):
                size = int(self.headers.get("Content-Length", 0))
                body = self.rfile.read(size)
                cls.calls.append(
                    (self.command, self.path, dict(self.headers), json.loads(body))
                )
                value = {"event_id": "$fixture"} if self.command == "PUT" else {"id": 1}
                response = json.dumps(value).encode()
                self.send_response(200)
                self.send_header("Content-Length", str(len(response)))
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(response)

            do_POST = receive
            do_PUT = receive

        cls.server = http.server.ThreadingHTTPServer(("10.87.0.1", 0), Receiver)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.root / "server.pem", cls.root / "server.key")
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        (cls.root / "request.php").write_text(
            """<?php
require """
            + repr(str(ROOT / "slsmassnotifyserver/EnterpriseDeliveryConnector.php"))
            + """;
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
try {
 if(isset($input['normalize'])) {$result=\\SLS\\MassNotify\\ScopedTls::normalize($input['normalize'],$input['now']??null);}
 else {$result=\\SLS\\MassNotify\\EnterpriseDeliveryConnector::send($input['destination'],[
  'title'=>str_repeat('A',80),'message'=>'Fixture full message','is_test'=>true,'event_id'=>'fixture-event']);}
 echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
"""
        )

    @classmethod
    def command(cls, args, **kwargs):
        return subprocess.run(
            args, cwd=cls.root, capture_output=True, check=True, **kwargs
        )

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(3)
        cls.temporary.cleanup()

    def call(self, tls=None, provider="gotify", host="fixture.test"):
        destination = {
            "id": "conn_" + "a" * 24,
            "name": "Fixture",
            "enabled": "1",
            "provider": provider,
            "origin": f"https://{host}:{self.server.server_port}",
            "pinned_ipv4": "10.87.0.1",
            "token": "fixture-token",
            "topic": "!room:fixture.test" if provider == "matrix" else "",
        }
        if tls is not None:
            destination["tls"] = tls
        return self.php({"destination": destination})

    def php(self, value):
        return json.loads(
            self.command(
                ["php", str(self.root / "request.php")],
                input=json.dumps(value).encode(),
            ).stdout
        )

    def test_ca_and_pin_are_scoped_and_hostname_checks_remain(self):
        ca = (self.root / "ca.pem").read_text()
        self.assertEqual(self.call({"ca_pem": ca})["result"]["state"], "accepted")
        self.assertEqual(self.call()["result"]["state"], "uncertain")
        self.assertEqual(
            self.call({"ca_pem": ca}, host="wrong.test")["result"]["state"], "uncertain"
        )
        self.assertEqual(
            self.call({"ca_pem": ca, "public_key_pin": self.pin})["result"]["state"],
            "accepted",
        )
        self.assertEqual(
            self.call(
                {
                    "ca_pem": ca,
                    "public_key_pin": "sha256//" + base64.b64encode(b"x" * 32).decode(),
                }
            )["result"]["state"],
            "uncertain",
        )
        self.assertEqual(self.call()["result"]["state"], "uncertain")

    def test_matrix_wire_contract_and_stable_transaction(self):
        policy = {"ca_pem": (self.root / "ca.pem").read_text()}
        first = self.call(policy, "matrix")["result"]
        second = self.call(policy, "matrix")["result"]
        self.assertEqual(first["state"], "accepted")
        self.assertEqual(first["provider_message_id"], "$fixture")
        self.assertEqual(second, first)
        one, two = self.calls[-2:]
        self.assertEqual(one[0], "PUT")
        self.assertEqual(one[1], two[1])
        self.assertIn("/rooms/%21room%3Afixture.test/send/m.room.message/", one[1])
        self.assertEqual(one[2]["Authorization"], "Bearer fixture-token")
        self.assertEqual(one[3]["msgtype"], "m.text")
        self.assertIn("Fixture full message", one[3]["body"])

    def test_ca_validation_rejects_non_ca_expired_and_private_keys(self):
        ca = (self.root / "ca.pem").read_text()
        self.assertIn("ca_pem", self.php({"normalize": {"ca_pem": ca}})["result"])
        self.assertIn(
            "error", self.php({"normalize": {"ca_pem": ca}, "now": 4102444800})
        )
        self.assertIn(
            "error",
            self.php({"normalize": {"ca_pem": (self.root / "server.pem").read_text()}}),
        )
        self.assertIn(
            "error",
            self.php(
                {"normalize": {"ca_pem": ca + (self.root / "ca.key").read_text()}}
            ),
        )
        self.assertIn(
            "error", self.php({"normalize": {"public_key_pin": "sha256//invalid"}})
        )
        self.assertIn("error", self.php({"normalize": {"verify_peer": False}}))


if __name__ == "__main__":
    unittest.main()
