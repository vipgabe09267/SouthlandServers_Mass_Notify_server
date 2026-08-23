#!/usr/bin/env python3
"""Contract tests for per-zone phone, desktop, and email routing."""

import configparser
from email import policy
from email.parser import BytesParser
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
WEATHER_WORKER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify_weather_poll.sh"
CONFIG_LOADER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_config.py"
EMAIL_MODULE_PATH = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_branded_email.py"


def fail(message):
    raise AssertionError(message)


def load_notify_module():
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location("sls_notify_zone_test", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_source_module(name, path):
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def alert_fixture():
    return {
        "id": "https://api.weather.gov/alerts/test-zone-routing",
        "properties": {
            "event": "Severe Thunderstorm Warning",
            "severity": "Severe",
            "urgency": "Immediate",
            "messageType": "Alert",
            "areaDesc": "Test Area",
            "description": "Test description",
        },
    }


def test_targeted_alert_journal():
    notify = load_notify_module()
    notify.build_xml = lambda config, alert: "<YealinkIPPhoneTextScreen/>"
    with tempfile.TemporaryDirectory(prefix="sls-zone-journal-") as temp_dir:
        events = Path(temp_dir) / "events.jsonl"
        config = configparser.ConfigParser(interpolation=None)
        config.read_dict({"api": {"events_file": str(events)}})
        notify.push_alert(
            config,
            alert_fixture(),
            api_only=True,
            desktop_targets=["desk.one"],
        )
        notify.push_alert(
            config,
            alert_fixture(),
            api_only=True,
            desktop_targets=["desk.two"],
        )
        records = [json.loads(line) for line in events.read_text(encoding="utf-8").splitlines() if line]
        if len(records) != 1:
            fail("Repeated zone publication did not merge the shared NWS alert journal record")
        record = records[0]
        if record.get("desktop_all") is not False:
            fail("A zone-specific Weather event was exposed as an all-desktops broadcast")
        if record.get("desktop_recipients") != ["desk.one", "desk.two"]:
            fail("Desktop recipients from overlapping Weather zones were not merged")
        if record.get("recipients") != []:
            fail("Desktop-only Weather publication invented a phone recipient")
        try:
            notify.push_alert(config, alert_fixture(), api_only=True)
        except RuntimeError as exc:
            if "desktop target" not in str(exc):
                raise
        else:
            fail("Targetless API-only Weather publication was accepted")


def test_mixed_channel_publication_is_atomic():
    notify = load_notify_module()
    notify.build_xml = lambda config, alert: "<YealinkIPPhoneTextScreen/>"
    notify.endpoint_format_overrides = lambda config: {}
    published = []
    batch_state = {"accepted": False}

    class FakeAmiClient:
        def __init__(self, *args, **kwargs):
            pass

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, traceback):
            return False

    notify.AmiClient = FakeAmiClient
    notify.append_sipnotify_event = lambda config, record: published.append(record)
    notify.send_notify_batch = lambda *args, **kwargs: batch_state.update(accepted=True)
    config = configparser.ConfigParser(interpolation=None)
    config.read_dict({"ami": {"host": "127.0.0.1", "port": "5038", "username": "test", "password": "test"}})

    notify.get_registered_endpoint_info = lambda *args, **kwargs: {
        "1000": {"format": "yealink", "formats": ["yealink"], "contacts": []}
    }
    try:
        notify.push_alert(
            config,
            alert_fixture(),
            targets=["1000", "1001"],
            desktop_targets=["desk.one"],
            retries=False,
            require_all_targets=True,
        )
    except RuntimeError as exc:
        if "1001" not in str(exc):
            raise
    else:
        fail("A mixed Weather test accepted only a subset of requested phone targets")
    if published or batch_state["accepted"]:
        fail("A targeted desktop record was published before every phone target passed validation")

    # Live zone delivery remains best-effort: one temporarily offline phone
    # must not suppress the registered phone or explicitly targeted desktops.
    notify.push_alert(
        config,
        alert_fixture(),
        targets=["1000", "1001"],
        desktop_targets=["desk.one"],
        retries=False,
    )
    if not batch_state["accepted"] or len(published) != 1:
        fail("Best-effort live Weather delivery did not use its available phone and desktop targets")
    if published[0].get("recipients") != [] or published[0].get("desktop_recipients") != ["desk.one"]:
        fail("Best-effort live Weather publication lost its targeted desktop or claimed unverified phone delivery")

    published.clear()
    batch_state["accepted"] = False

    notify.get_registered_endpoint_info = lambda *args, **kwargs: {
        "1000": {"format": "yealink", "formats": ["yealink"], "contacts": []},
        "1001": {"format": "yealink", "formats": ["yealink"], "contacts": []},
    }
    notify.push_alert(
        config,
        alert_fixture(),
        targets=["1000", "1001"],
        desktop_targets=["desk.one"],
        retries=False,
        require_all_targets=True,
    )
    if not batch_state["accepted"] or len(published) != 1:
        fail("Desktop publication did not follow a fully accepted phone SIP NOTIFY batch")
    if published[0].get("desktop_recipients") != ["desk.one"]:
        fail("The successful mixed-channel record lost its targeted desktop recipient")

    class FailingAmiClient(FakeAmiClient):
        def __enter__(self):
            raise RuntimeError("synthetic AMI connection failure")

    notify.AmiClient = FailingAmiClient
    published.clear()
    try:
        notify.push_alert(
            config,
            alert_fixture(),
            targets=["1000"],
            desktop_targets=["desk.one"],
            retries=False,
        )
    except RuntimeError as exc:
        if "AMI connection" not in str(exc):
            raise
    else:
        fail("Synthetic AMI connection failure was not surfaced")
    if len(published) != 1 or published[0].get("desktop_recipients") != ["desk.one"]:
        fail("A live targeted desktop alert was lost when AMI connection failed")

    published.clear()
    try:
        notify.push_alert(
            config,
            alert_fixture(),
            targets=["1000"],
            desktop_targets=["desk.one"],
            retries=False,
            require_all_targets=True,
        )
    except RuntimeError as exc:
        if "AMI connection" not in str(exc):
            raise
    else:
        fail("Strict manual delivery hid a synthetic AMI connection failure")
    if published:
        fail("A strict manual test published a desktop alert before AMI validation")

    notify.AmiClient = FakeAmiClient
    notify.get_registered_endpoint_info = lambda *args, **kwargs: {
        "1000": {"format": "yealink", "formats": ["yealink"], "contacts": []}
    }
    notify.send_notify_batch = lambda *args, **kwargs: (_ for _ in ()).throw(
        RuntimeError("synthetic SIP submission failure")
    )
    published.clear()
    try:
        notify.push_alert(
            config,
            alert_fixture(),
            targets=["1000"],
            desktop_targets=["desk.one"],
            retries=False,
        )
    except RuntimeError as exc:
        if "SIP submission" not in str(exc):
            raise
    else:
        fail("Synthetic SIP submission failure was not surfaced")
    if len(published) != 1 or published[0].get("desktop_recipients") != ["desk.one"]:
        fail("A live targeted desktop alert was lost when SIP submission failed")

    published.clear()
    try:
        notify.push_alert(
            config,
            alert_fixture(),
            targets=["1000"],
            desktop_targets=["desk.one"],
            retries=False,
            require_all_targets=True,
        )
    except RuntimeError as exc:
        if "SIP submission" not in str(exc):
            raise
    else:
        fail("Strict manual delivery hid a synthetic SIP submission failure")
    if published:
        fail("A strict manual test published a desktop alert before SIP submission succeeded")


def test_weather_worker_overrides():
    with tempfile.TemporaryDirectory(prefix="sls-zone-worker-") as temp_dir:
        temp = Path(temp_dir)
        runtime = temp / "runtime"
        data = temp / "data"
        runtime.mkdir()
        data.mkdir()
        capture = temp / "captured.jsonl"
        fake_core = runtime / "sls_mass_notify_nws_poll.sh"
        fake_core.write_text(
            "#!/usr/bin/env python3\n"
            "import json, os\n"
            f"open({str(capture)!r}, 'a', encoding='utf-8').write(json.dumps({{"
            "'id': os.environ.get('NWS_ZONE_GROUP_ID_OVERRIDE'),"
            "'zone': os.environ.get('NWS_ZONE_OVERRIDE'),"
            "'phones': os.environ.get('NWS_RECIPIENTS_OVERRIDE'),"
            "'desktops': os.environ.get('NWS_DESKTOP_CLIENTS_OVERRIDE'),"
            "'emails': os.environ.get('NWS_EMAIL_RECIPIENTS_OVERRIDE')}) + '\\n')\n",
            encoding="utf-8",
        )
        fake_core.chmod(0o755)
        config_path = temp / "mass-notifications.config"
        config = {
            "enabled": "1",
            "nws_zone": "TXC491",
            "alert_recipients": [],
            "desktop_clients": [
                {"username": "desk.one", "enabled": "1"},
                {"username": "desk.disabled", "enabled": "0"},
            ],
            "nws_zones": [
                {
                    "name": "Central",
                    "zone": "TXC491",
                    "extensions": [],
                    "desktop_clients": ["desk.one", "desk.disabled", "missing"],
                    "email_recipients": ["zone@example.com"],
                },
                {
                    "id": "phones_only",
                    "name": "Phones Only",
                    "zone": "TXC493",
                    "extensions": ["1000"],
                    "desktop_clients": [],
                    "email_recipients": [],
                },
            ],
        }
        config_path.write_text(json.dumps(config), encoding="utf-8")
        environment = os.environ.copy()
        environment.update({
            "CONFIG_FILE": str(config_path),
            "RUNTIME_DIR": str(runtime),
            "DATA_DIR": str(data),
            "LOG": str(temp / "worker.log"),
        })
        subprocess.run([str(WEATHER_WORKER)], env=environment, check=True, timeout=15)
        captured = [
            json.loads(line)
            for line in capture.read_text(encoding="utf-8").splitlines()
            if line
        ]
        legacy_id = "nws_" + hashlib.sha256(b"central|TXC491").hexdigest()[:12]
        expected = [
            {
                "id": legacy_id,
                "zone": "TXC491",
                "phones": "",
                "desktops": "desk.one",
                "emails": "zone@example.com",
            },
            {
                "id": "phones_only",
                "zone": "TXC493",
                "phones": "1000",
                "desktops": "",
                "emails": "",
            },
        ]
        captured.sort(key=lambda item: item["id"])
        expected.sort(key=lambda item: item["id"])
        if captured != expected:
            fail(f"Weather worker did not isolate zone destinations: {captured!r}")

        loader_result = subprocess.run(
            [sys.executable, str(CONFIG_LOADER), str(config_path)],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=10,
        )
        if loader_result.returncode != 0:
            fail("Desktop-only first Weather zone was rejected by the central config loader: " + loader_result.stderr.decode("utf-8", "replace"))
        parts = loader_result.stdout.split(b"\0")
        pairs = list(zip(parts[0::2], parts[1::2]))
        emitted = {}
        for key, value in pairs:
            if not key:
                continue
            emitted.setdefault(key.decode("utf-8"), []).append(value.decode("utf-8"))
        if emitted.get("NWS_DESKTOP_CLIENT") != ["desk.one"]:
            fail(f"Central config loader did not emit the enabled primary-zone desktop: {emitted!r}")
        if emitted.get("NWS_ZONE_EMAIL_RECIPIENT") != ["zone@example.com"]:
            fail(f"Central config loader did not emit primary-zone email recipients: {emitted!r}")

        config["mail_to"] = "Legacy@Example.net legacy@example.net"
        config_path.write_text(json.dumps(config), encoding="utf-8")
        legacy_result = subprocess.run(
            [sys.executable, str(CONFIG_LOADER), str(config_path)],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=10,
        )
        if legacy_result.returncode != 0:
            fail("Legacy Weather email routing was rejected by the central config loader")
        legacy_parts = legacy_result.stdout.split(b"\0")
        legacy_emitted = {}
        for key, value in zip(legacy_parts[0::2], legacy_parts[1::2]):
            if key:
                legacy_emitted.setdefault(key.decode("utf-8"), []).append(value.decode("utf-8"))
        if legacy_emitted.get("NWS_ZONE_EMAIL_RECIPIENT") != ["zone@example.com", "Legacy@Example.net"]:
            fail(f"Legacy live-alert recipients were not migrated into the Weather route: {legacy_emitted!r}")
        if any(value for value in legacy_emitted.get("MAIL_TO", []) if value):
            fail("Legacy live-alert recipients leaked into the system/error MAIL_TO channel")

        config["system_notification_emails"] = ""
        config_path.write_text(json.dumps(config), encoding="utf-8")
        canonical_result = subprocess.run(
            [sys.executable, str(CONFIG_LOADER), str(config_path)],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=10,
        )
        canonical_parts = canonical_result.stdout.split(b"\0")
        canonical_emitted = {}
        for key, value in zip(canonical_parts[0::2], canonical_parts[1::2]):
            if key:
                canonical_emitted.setdefault(key.decode("utf-8"), []).append(value.decode("utf-8"))
        if canonical_emitted.get("NWS_ZONE_EMAIL_RECIPIENT") != ["zone@example.com"]:
            fail("An explicit canonical system list did not disable legacy service fallback")


def test_email_deduplication():
    email_module = load_source_module("sls_branded_email_zone_test", EMAIL_MODULE_PATH)
    captured = {}

    def fake_run(command, input=None, **kwargs):
        captured["message"] = input
        return subprocess.CompletedProcess(command, 0)

    email_module.subprocess.run = fake_run
    result = email_module.send_branded_email(
        {
            "mail_from_name": "SLS Test",
            "mail_from_local_part": "no-reply",
            "mail_from_domain": "pbx.example.com",
        },
        "Weather test",
        "Body",
        recipients_override="GLOBAL@example.com global@example.com zone@example.net ZONE@example.net",
    )
    if result is not True or not isinstance(captured.get("message"), bytes):
        fail("Branded email test did not reach the bounded sendmail submission")
    message = BytesParser(policy=policy.default).parsebytes(captured["message"])
    bcc = [address.strip() for address in str(message.get("Bcc") or "").split(",") if address.strip()]
    if bcc != ["GLOBAL@example.com", "zone@example.net"]:
        fail(f"Global and zone email recipients were not deduplicated case-insensitively: {bcc!r}")

    captured.clear()
    empty_result = email_module.send_branded_email(
        {
            "mail_from_name": "SLS Test",
            "mail_from_local_part": "no-reply",
            "mail_from_domain": "pbx.example.com",
            "system_notification_emails": "system@example.com",
            "mail_to": "legacy@example.com",
        },
        "Weather test",
        "Body",
        recipients_override="",
    )
    if empty_result is not False or captured:
        fail("An explicitly empty service email route fell back to system or legacy recipients")

    too_many = " ".join(f"recipient{index}@example.com" for index in range(51))
    try:
        email_module.send_branded_email(
            {
                "mail_from_name": "SLS Test",
                "mail_from_local_part": "no-reply",
                "mail_from_domain": "pbx.example.com",
            },
            "Weather test",
            "Body",
            recipients_override=too_many,
        )
    except RuntimeError as exc:
        if "50-recipient" not in str(exc):
            raise
    else:
        fail("An oversized global plus zone email list was silently truncated")


def main():
    test_targeted_alert_journal()
    test_mixed_channel_publication_is_atomic()
    test_weather_worker_overrides()
    test_email_deduplication()
    print("NWS zone destination Python contract tests passed.")


if __name__ == "__main__":
    main()
