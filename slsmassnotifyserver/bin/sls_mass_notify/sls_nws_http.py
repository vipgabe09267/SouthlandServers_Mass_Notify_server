#!/usr/bin/python3
"""Conditional Weather.gov GETs with one cumulative retry deadline."""
from __future__ import annotations

import base64
from email.utils import parsedate_to_datetime
import hashlib
import os
from pathlib import Path
import random
import re
import subprocess
import sys
import tempfile
import time
import zlib

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).absolute().parent))
from sls_atomic_json import read_json, locked_json
from sls_weather_payload import strict_json, validate_collection

DATA = Path("/var/lib/asterisk/SLS_Mass_Notifications_Plugin")
MAX_BODY = 10 * 1024 * 1024
MAX_CACHE = 32 * 1024 * 1024
USER_AGENT = "SouthlandServers-Mass-Notifications-Server (https://github.com/vipgabe09267/SouthlandServers_Mass_Notify_server)"


def validated(raw: bytes) -> bytes:
    if not raw or len(raw) > MAX_BODY:
        raise ValueError("Weather.gov response size is invalid")
    validate_collection(strict_json(raw))
    return raw


def cached_body(entry: dict) -> bytes:
    if (
        not isinstance(entry, dict)
        or type(entry.get("bytes")) is not int
        or not 0 < entry["bytes"] <= MAX_BODY
    ):
        raise ValueError("Invalid weather HTTP cache")
    encoded = entry.get("zlib_base64")
    if not isinstance(encoded, str) or len(encoded) > MAX_BODY * 2:
        raise ValueError("Invalid weather cache encoding")
    compressed = base64.b64decode(encoded, validate=True)
    if base64.b64encode(compressed).decode() != encoded:
        raise ValueError("Invalid weather cache encoding")
    decoder = zlib.decompressobj()
    raw = decoder.decompress(compressed, MAX_BODY + 1)
    if (
        not decoder.eof
        or decoder.unused_data
        or decoder.unconsumed_tail
        or len(raw) != entry["bytes"]
        or hashlib.sha256(raw).hexdigest() != entry.get("sha256")
    ):
        raise ValueError("Weather HTTP cache integrity failed")
    return validated(raw)


def validator(value):
    return (
        value
        if isinstance(value, str)
        and 1 <= len(value) <= 512
        and re.fullmatch(r"[\x20-\x7e]+", value)
        else None
    )


def retry_delay(value, now):
    if isinstance(value, str) and re.fullmatch(r"[0-9]{1,6}", value):
        return min(86400, int(value))
    if isinstance(value, str) and len(value) <= 80:
        try:
            date = parsedate_to_datetime(value)
            if date.tzinfo is not None:
                return min(86400, max(0, date.timestamp() - now))
        except (ValueError, TypeError, OverflowError):
            pass
    return None


def update_cache(path, zone, *, record=None, retry_until=None):
    try:
        with locked_json(path, maximum=MAX_CACHE, runtime_owner=True) as current:
            if not current:
                current.update({"schema": 1, "zones": {}, "backoffs": {}})
            if current.get("schema") != 1 or set(current) - {
                "schema",
                "zones",
                "backoffs",
            }:
                raise ValueError("Invalid weather cache")
            for name in ("zones", "backoffs"):
                current.setdefault(name, {})
                if (
                    not isinstance(current[name], dict)
                    or len(current[name]) > 50
                    or any(
                        not re.fullmatch(r"[A-Z]{2}[CZ][0-9]{3}", key)
                        for key in current[name]
                    )
                ):
                    raise ValueError("Invalid weather cache")
            if record is not None:
                zones = current["zones"]
                if any(
                    not isinstance(value, dict)
                    or type(value.get("checked_at")) is not int
                    for value in zones.values()
                ):
                    raise ValueError("Invalid weather cache")
                if zone not in zones and len(zones) >= 50:
                    del zones[min(zones, key=lambda key: zones[key]["checked_at"])]
                zones[zone] = record
                current["backoffs"].pop(zone, None)
            if retry_until is not None:
                backoffs = current["backoffs"]
                if any(type(value) is not int for value in backoffs.values()):
                    raise ValueError("Invalid weather backoff cache")
                if zone not in backoffs and len(backoffs) >= 50:
                    del backoffs[min(backoffs, key=backoffs.get)]
                backoffs[zone] = retry_until
    except (OSError, RuntimeError, ValueError, TypeError, AttributeError):
        # HTTP caches are advisory. An unwritable or corrupt cache cannot turn
        # a fresh, validated official response into a delivery failure.
        return False
    return True


def curl_get(zone, headers, seconds, ipv4):
    with tempfile.TemporaryDirectory(prefix="sls-weather-get-") as temporary:
        folder = Path(temporary)
        body = folder / "body"
        header_file = folder / "headers"
        command = [
            "/usr/bin/prlimit",
            "--fsize=" + str(MAX_BODY),
            "--",
            "/usr/bin/curl",
            "-q",
            "--silent",
            "--show-error",
            "--proto",
            "=https",
            "--tlsv1.2",
            "--max-redirs",
            "0",
            "--connect-timeout",
            str(min(12, seconds)),
            "--max-time",
            str(seconds),
            "--max-filesize",
            str(MAX_BODY),
            "--dump-header",
            str(header_file),
            "--output",
            str(body),
            "--write-out",
            "%{http_code}",
            "--header",
            "Accept: application/geo+json",
            "--user-agent",
            USER_AGENT,
        ]
        if ipv4:
            command.append("--ipv4")
        for name, value in headers.items():
            command.extend(["--header", name + ": " + value])
        command.append(
            "https://api.weather.gov/alerts/active?zone=" + zone + "&status=actual"
        )
        environment = {"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"}
        for name in (
            "HTTPS_PROXY",
            "https_proxy",
            "ALL_PROXY",
            "all_proxy",
            "NO_PROXY",
            "no_proxy",
        ):
            value = os.environ.get(name)
            if (
                value
                and len(value) <= 2048
                and not re.search(r"[\x00-\x1f\x7f]", value)
            ):
                environment[name] = value
        result = subprocess.run(
            command,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=seconds + 0.5,
            check=False,
            env=environment,
        )
        if result.returncode or not re.fullmatch(rb"[0-9]{3}", result.stdout):
            if result.returncode in (5, 6):
                detail = "DNS resolution failed"
            elif result.returncode in (35, 51, 58, 60, 77, 83):
                detail = "TLS verification or connection failed; check PBX time and trusted CA certificates"
            elif result.returncode == 28:
                detail = "connection or DNS resolution timed out"
            elif result.returncode in (63, -25):
                detail = "response exceeded the size limit"
            else:
                detail = "transport failed (curl exit " + str(result.returncode) + ")"
            raise OSError("Weather.gov " + detail)

        with header_file.open("rb") as stream:
            raw_headers = stream.read(65537)
        if len(raw_headers) > 65536:
            raise ValueError("Weather.gov headers exceeded 64 KiB")
        response_headers = {}
        for line in raw_headers.decode("iso-8859-1").splitlines():
            if line.startswith("HTTP/"):
                response_headers = {}
            elif ":" in line:
                name, value = line.split(":", 1)
                name = name.lower()
                value = value.strip()
                if name in ("etag", "last-modified", "retry-after"):
                    if name in response_headers:
                        raise ValueError("Weather.gov returned ambiguous cache headers")
                    response_headers[name] = value
        try:
            with body.open("rb") as stream:
                raw = stream.read(MAX_BODY + 1)
        except FileNotFoundError:
            raw = b""
        if len(raw) > MAX_BODY:
            raise ValueError("Weather.gov body exceeded its size limit")
        return int(result.stdout), response_headers, raw


def fetch(
    zone,
    *,
    directory=DATA,
    transport=curl_get,
    clock=time.monotonic,
    sleep=time.sleep,
    jitter=random.uniform,
    wall=time.time,
    budget=25,
):
    if not isinstance(zone, str) or not re.fullmatch(r"[A-Z]{2}[CZ][0-9]{3}", zone):
        raise ValueError("Use a valid Weather.gov forecast or county zone")
    if (
        not isinstance(budget, (int, float))
        or isinstance(budget, bool)
        or not 1 <= budget <= 30
    ):
        raise ValueError("Weather HTTP budget must be 1–30 seconds")
    path = Path(directory) / "weather-http-cache.json"
    entry = {}
    raw_cache = None
    backoff = None
    try:
        cache = read_json(path, maximum=MAX_CACHE, runtime_owner=True)
        if cache and (
            cache.get("schema") != 1
            or not isinstance(cache.get("zones"), dict)
            or len(cache["zones"]) > 50
        ):
            raise ValueError("Invalid weather cache")
        backoffs = cache.get("backoffs", {})
        if not isinstance(backoffs, dict) or len(backoffs) > 50:
            raise ValueError("Invalid weather backoff cache")
        backoff = backoffs.get(zone)
        entry = cache.get("zones", {}).get(zone, {})
        if entry:
            raw_cache = cached_body(entry)
    except (OSError, RuntimeError, ValueError, zlib.error):
        entry = {}
        raw_cache = None
        backoff = None
    deadline = clock() + budget
    if type(backoff) is int and wall() < backoff <= wall() + 86400:
        raise OSError("Weather.gov requested backoff; no cached alerts were delivered")
    headers = {}
    if raw_cache is not None:
        for stored, name in [
            ("etag", "If-None-Match"),
            ("last_modified", "If-Modified-Since"),
        ]:
            value = validator(entry.get(stored))
            if value:
                headers[name] = value
    last_error = "Weather.gov did not return a valid response"
    for attempt in range(3):
        remaining = deadline - clock()
        if remaining <= 0.5:
            break
        retry = None
        try:
            status, response_headers, raw = transport(
                zone, headers, min(12, remaining - 0.25), attempt > 0
            )
            if clock() > deadline:
                raise OSError("Weather.gov exceeded the cumulative deadline")
            if status == 304:
                if raw_cache is None:
                    headers = {}
                    last_error = "Weather.gov returned 304 without a valid local body"
                    continue
                output = raw_cache
            elif status == 200:
                output = validated(raw)
            else:
                if status not in (429, 500, 502, 503, 504):
                    raise ValueError(
                        "Weather.gov rejected the request with HTTP " + str(status)
                    )
                retry = retry_delay(response_headers.get("retry-after"), wall())
                if retry is not None and retry > 0:
                    update_cache(path, zone, retry_until=int(wall() + retry))
                raise OSError(
                    "Weather.gov is temporarily unavailable (HTTP " + str(status) + ")"
                )
            etag = validator(response_headers.get("etag"))
            modified = validator(response_headers.get("last-modified"))
            if status == 304:
                etag = etag or validator(entry.get("etag"))
                modified = modified or validator(entry.get("last_modified"))
            record = {
                "checked_at": int(wall()),
                "bytes": len(output),
                "sha256": hashlib.sha256(output).hexdigest(),
                "zlib_base64": base64.b64encode(zlib.compress(output, 3)).decode(),
                "etag": etag,
                "last_modified": modified,
            }
            update_cache(path, zone, record=record)
            return output
        except (OSError, subprocess.SubprocessError) as error:
            last_error = str(error)
        if attempt == 2:
            break
        delay = (retry if retry is not None else 0.5 * (2**attempt)) + jitter(0.1, 0.6)
        if delay >= deadline - clock() - 0.5:
            break
        sleep(delay)
    raise OSError(last_error + "; no stale cache was replayed")


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: sls_nws_http.py WEATHER_ZONE")
    sys.stdout.buffer.write(fetch(sys.argv[1]))


if __name__ == "__main__":
    try:
        main()
    except (OSError, RuntimeError, ValueError, zlib.error) as error:
        print("Weather.gov fetch: " + str(error), file=sys.stderr)
        raise SystemExit(1)
