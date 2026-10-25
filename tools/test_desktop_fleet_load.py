#!/usr/bin/python3
"""Private two-worker FPM fleet benchmark; never targets the production PBX."""
import base64
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import importlib.util
import json
import math
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import socket
import struct
import subprocess
import tempfile
import threading
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
if os.environ.get("SLS_TEST_NAMESPACE") != "entered":
    raise SystemExit("Use the private fixture wrapper.")
spec = importlib.util.spec_from_file_location(
    "fleet_fpm_protocol", ROOT / "tools/test_served_php_fpm_cache.py"
)
wire = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wire)


def fetch(address, script, username=None, etag="", timeout=10):
    values = {
        "SCRIPT_FILENAME": str(script),
        "REQUEST_METHOD": "GET",
        "REQUEST_URI": "/api/sipnotify/desktop",
        "PATH_INFO": "/desktop",
        "QUERY_STRING": "limit=1" + ("&last_event_id=bench-19" if etag else ""),
        "REMOTE_ADDR": "192.0.2.25",
        "HTTPS": "on",
        "SERVER_NAME": "pbx.example.test",
        "SERVER_PROTOCOL": "HTTP/1.1",
        "GATEWAY_INTERFACE": "CGI/1.1",
    }
    if username is not None:
        values["HTTP_AUTHORIZATION"] = (
            "Basic "
            + base64.b64encode((username + ":fixture-password").encode()).decode()
        )
        values.update({"HTTP_X_SLS_CLIENT_VERSION":"1.0.12-beta",
            "HTTP_X_SLS_PAYLOAD_SCHEMA":"1","HTTP_X_SLS_SSE_PROTOCOL":"2"})
    if etag:
        values["HTTP_IF_NONE_MATCH"] = etag
    parameters = b""
    for key, value in values.items():
        key, value = key.encode(), value.encode()
        parameters += wire.length(len(key)) + wire.length(len(value)) + key + value
    client = socket.socket(socket.AF_UNIX)
    client.settimeout(timeout)
    output = bytearray()
    began = time.monotonic()
    try:
        client.connect(str(address))
        client.sendall(
            wire.record(1, struct.pack("!HB5x", 1, 0))
            + wire.record(4, parameters)
            + wire.record(4)
            + wire.record(5)
        )

        def read(size):
            body = bytearray()
            while len(body) < size:
                block = client.recv(size - len(body))
                if not block:
                    raise RuntimeError("Private FPM response ended early")
                body.extend(block)
            return bytes(body)

        while len(output) <= 1048576:
            version, kind, identity, size, padding, _ = struct.unpack(
                "!BBHHBB", read(8)
            )
            body = read(size)
            read(padding)
            if version != 1 or identity != 1:
                raise RuntimeError("Invalid private FPM frame")
            if kind == 6:
                output.extend(body)
            if kind == 3:
                break
        else:
            raise RuntimeError("Private FPM response too large")
    finally:
        client.close()
    headers, body = bytes(output).split(b"\r\n\r\n", 1)
    metadata = {}
    for line in headers.decode().split("\r\n"):
        name, _, value = line.partition(":")
        metadata[name.lower()] = value.strip()
    return json.loads(body), metadata, (time.monotonic() - began) * 1000


def pool_stats(pid):
    ticks = 0
    rss = 0
    try:
        children = [
            int(value)
            for value in Path(f"/proc/{pid}/task/{pid}/children").read_text().split()
        ]
    except OSError:
        children = []
    for child in [pid, *children]:
        try:
            raw = Path(f"/proc/{child}/stat").read_text().rsplit(")", 1)[1].split()
            ticks += int(raw[11]) + int(raw[12])
            rss += int(raw[21]) * os.sysconf("SC_PAGE_SIZE")
        except (OSError, ValueError, IndexError):
            pass
    return ticks / os.sysconf("SC_CLK_TCK"), rss


def percentile(values, p):
    return sorted(values)[max(0, math.ceil(len(values) * p) - 1)]


class FleetLoad(unittest.TestCase):
    def test_authenticated_fleet_shares_one_ip_without_blocking_control_requests(self):
        account = pwd.getpwnam("asterisk")
        extended = os.environ.get("SLS_FLEET_BENCHMARK") == "1"
        waves = int(os.environ.get("SLS_FLEET_BENCHMARK_WAVES", "6" if extended else "1"))
        if not 1 <= waves <= 6:
            raise ValueError("Use 1–6 five-second benchmark waves within the fixture deadline.")
        tiers = [25, 100, 250, 500, 1000] if extended else [25]
        with tempfile.TemporaryDirectory(prefix="sls-fleet-load-") as temporary:
            root = Path(temporary)
            root.chmod(0o755)
            module = root / "module"
            shutil.copytree(ROOT / "slsmassnotifyserver", module)
            for path in [module, *module.rglob("*")]:
                path.chmod(0o755 if path.is_dir() else 0o644)
            data = root / "data"
            data.mkdir(mode=0o750)
            os.chown(data, account.pw_uid, account.pw_gid)
            script = module / "api/sipnotify/index.php"
            source = script.read_text()
            for name, path in {
                "EVENTS_FILE": data / "events.jsonl",
                "SETTINGS_FILE": data / "settings.json",
                "DESKTOP_LAST_SEEN_FILE": data / "seen.json",
                "DESKTOP_AUTH_RATE_FILE": data / "rates.json",
                "DESKTOP_ACK_DIRECTORY": data / "acks",
            }.items():
                source, count = re.subn(
                    r"const " + name + r" = '[^']*';",
                    "const " + name + " = " + json.dumps(str(path)) + ";",
                    source,
                    count=1,
                )
                self.assertEqual(count, 1)
            script.write_text(source)
            prepare = root / "prepare.php"
            prepare.write_text(
                """<?php
            $key=random_bytes(32);$clients=[];for($i=0;$i<1000;$i++){$nonce=random_bytes(12);$cipher=openssl_encrypt('fixture-password','aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);$clients[]=['client_id'=>'cli_'.str_pad((string)$i,24,'0',STR_PAD_LEFT),'username'=>'fleet'.$i,'enabled'=>true,'password_enc'=>'v1:'.base64_encode($nonce.$tag.$cipher)];}
            file_put_contents($argv[1],json_encode(['public_pbx_host'=>'pbx.example.test','desktop_auth_key'=>base64_encode($key),'desktop_client_limit'=>1000,'desktop_clients'=>$clients]));"""
            )
            subprocess.run(
                ["php", str(prepare), str(data / "settings.json")],
                check=True,
                timeout=10,
            )
            settings = json.loads((data / "settings.json").read_text())
            bindings = {
                c["username"]: c["client_id"] for c in settings["desktop_clients"]
            }
            stamp = datetime.now(timezone.utc).isoformat()
            events = []
            for index in range(20):
                events.append(
                    {
                        "id": "bench-" + str(index),
                        "kind": "announcement",
                        "created_at": stamp,
                        "message": "Private load fixture",
                        "is_test": True,
                        "desktop_all": True,
                        "desktop_recipients": [],
                        "desktop_client_ids": bindings,
                    }
                )
            (data / "events.jsonl").write_text(
                "".join(
                    json.dumps(event, separators=(",", ":")) + "\n" for event in events
                )
            )
            for path in (data / "settings.json", data / "events.jsonl"):
                path.chmod(0o640)
                os.chown(path, account.pw_uid, account.pw_gid)
            import sys

            sys.path.insert(0, str(ROOT / "slsmassnotifyserver/bin/sls_mass_notify"))
            import sls_desktop_feed_cache as feed_cache

            self.assertTrue(
                feed_cache.publish(
                    data / "events.jsonl",
                    [json.dumps(event, separators=(",", ":")) for event in events],
                    settings["desktop_auth_key"],
                )
            )

            health = root / "control.php"
            health.write_text(
                '<?php header("Content-Type: application/json");echo \'{"ok":true}\';'
            )
            health.chmod(0o644)
            address = root / "fpm.sock"
            configuration = root / "fpm.conf"
            configuration.write_text(
                f"""[global]
            pid={root}/fpm.pid
            error_log={root}/fpm.log
            daemonize=no
            [isolated]
            user=asterisk
            group=asterisk
            listen={address}
            listen.mode=0600
            listen.backlog=1024
            pm=static
            pm.max_children=2
            catch_workers_output=yes
            """
            )
            configuration.write_text(
                "\n".join(
                    line.strip() for line in configuration.read_text().splitlines()
                )
                + "\n"
            )
            check = subprocess.run(
                ["/usr/sbin/php-fpm8.2", "--test", "--fpm-config", str(configuration)],
                capture_output=True,
                text=True,
                timeout=10,
            )
            self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
            process = subprocess.Popen(
                [
                    "/usr/sbin/php-fpm8.2",
                    "--nodaemonize",
                    "--fpm-config",
                    str(configuration),
                    "-c",
                    "/etc/php/8.2/cli/php.ini",
                ],
                env={**os.environ, "PHP_INI_SCAN_DIR": "/etc/php/8.2/cli/conf.d"},
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                start_new_session=True,
            )
            try:
                for _ in range(100):
                    try:
                        if fetch(address, health)[0]["ok"]:
                            break
                    except (OSError, RuntimeError):
                        time.sleep(0.05)
                else:
                    self.fail(
                        "Private FPM pool did not become ready: "
                        + str(process.poll())
                        + " "
                        + (
                            (root / "fpm.log").read_text()[-1600:]
                            if (root / "fpm.log").exists()
                            else "No FPM log"
                        )
                    )
                validators = {}
                results = []
                for count in tiers:
                    with ThreadPoolExecutor(max_workers=16) as pool:
                        replies = list(
                            pool.map(
                                lambda i: (i, fetch(address, script, "fleet" + str(i))),
                                range(count),
                            )
                        )
                    for i, (body, headers, _) in replies:
                        self.assertTrue(body.get("ok"), body)
                        if "etag" not in headers:
                            body, headers, _ = fetch(address, script, "fleet" + str(i))
                        self.assertIn("etag", headers)
                        validators[i] = headers["etag"]
                    control = []
                    stop = threading.Event()
                    control_errors = []
                    cpu_start = pool_stats(process.pid)[0]
                    resource_samples = []
                    sample_start = time.monotonic()

                    def probe():
                        while not stop.is_set():
                            try:
                                control.append(fetch(address, health)[2])
                                resource_samples.append(pool_stats(process.pid))
                            except Exception as error:
                                control_errors.append(type(error).__name__)
                            stop.wait(0.1)

                    thread = threading.Thread(target=probe)
                    thread.start()
                    latencies = []
                    try:
                        for wave in range(waves):
                            began = time.monotonic()

                            def poll(i):
                                due = began + i * (5 / count)
                                remaining = due - time.monotonic()
                                if remaining > 0:
                                    time.sleep(remaining)
                                body, headers, elapsed = fetch(
                                    address, script, "fleet" + str(i), validators[i]
                                )
                                if "etag" in headers:
                                    validators[i] = headers["etag"]
                                return body, headers, elapsed

                            with ThreadPoolExecutor(max_workers=32) as pool:
                                replies = list(pool.map(poll, range(count)))
                            for body, headers, elapsed in replies:
                                self.assertTrue(body.get("ok"), body)
                                self.assertEqual(body["events"], [])
                                self.assertEqual(body["last_event_id"], "bench-19")
                                latencies.append(elapsed)
                            if extended:
                                stop.wait(max(0, 5 - (time.monotonic() - began)))
                    finally:
                        stop.set()
                        thread.join(timeout=15)
                    self.assertFalse(control_errors, control_errors)
                    self.assertTrue(control)
                    result = {
                        "clients": count,
                        "poll_interval_seconds": 5,
                        "workers": 2,
                        "journal_bytes": (data / "events.jsonl").stat().st_size,
                        "polls": len(latencies),
                        "poll_p95_ms": round(percentile(latencies, 0.95), 2),
                        "control_probe_p95_ms": round(percentile(control, 0.95), 2),
                        "cpu_core_equivalents": round(
                            max(0, pool_stats(process.pid)[0] - cpu_start)
                            / max(0.001, time.monotonic() - sample_start),
                            3,
                        ),
                        "peak_summed_process_rss_mib": round(
                            max([value[1] for value in resource_samples] or [0])
                            / 1048576,
                            1,
                        ),
                        "qualified": "private fixture only; not a FreePBX or hardware load certification",
                    }
                    self.assertLess(percentile(control, 0.95), 2000)
                    self.assertLess(percentile(latencies, 0.95), 5000)
                    results.append(result)
                print(
                    "SLS_FLEET_RESULTS " + json.dumps(results, separators=(",", ":")),
                    flush=True,
                )
                unchanged, metadata, _ = fetch(address, script, "fleet0", validators[0])
                self.assertTrue(unchanged.get("ok"), unchanged)
                self.assertEqual(unchanged["events"], [])
                # Validators intentionally expire each minute. A long benchmark
                # may need one full read before receiving the current validator.
                self.assertIn("etag", metadata)
                if metadata["etag"] == validators[0]:
                    self.assertTrue(unchanged.get("unchanged"), unchanged)
                # Current authentication is mandatory even when an old validator matches.
                settings["desktop_clients"][0]["enabled"] = False
                (data / "settings.json").write_text(json.dumps(settings))
                (data / "settings.json").chmod(0o640)
                denied, headers, _ = fetch(address, script, "fleet0", validators[0])
                self.assertFalse(denied["ok"])
                self.assertEqual(denied["error"], "unauthorized")
            finally:
                if process.poll() is None:
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
