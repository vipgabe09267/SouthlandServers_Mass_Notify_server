#!/usr/bin/env python3
"""Regression checks for branded-email sender-domain migration and validation."""

import importlib.util
import sys
from email import policy
from email.parser import BytesParser
from email.utils import parseaddr
from pathlib import Path
from unittest.mock import patch


sys.dont_write_bytecode = True


ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver" / "bin" / "sls_mass_notify"


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


email = load_module("sls_branded_email_test", RUNTIME / "sls_branded_email.py")
config = load_module("sls_config_test", RUNTIME / "sls_config.py")

valid = {
    "PBX.Example.com": "pbx.example.com",
    "@example.com": "example.com",
    "alerts.example.com.": "alerts.example.com",
}
invalid = [
    "localhost",
    "https://example.com",
    "no-reply@example.com",
    "192.0.2.10",
    "bad..example.com",
    "_mail.example.com",
    "-bad.example.com",
    "bad-.example.com",
    "a" * 64 + ".example.com",
    "example.com\r\nBcc: attacker@example.com",
]

for runtime in (email, config):
    for source, expected in valid.items():
        assert runtime.normalized_sender_domain(source) == expected, source
    for source in invalid:
        assert runtime.normalized_sender_domain(source) == "", source

for runtime in (email, config):
    assert runtime.normalized_sender_local_part("Alerts.Team+PBX") == "alerts.team+pbx"
    for invalid_local_part in ("", ".alerts", "alerts.", "alerts..pbx", "bad space", "a" * 65):
        assert runtime.normalized_sender_local_part(invalid_local_part) == ""

assert email.sender_address({"mail_from_domain": "example.com", "mail_from_local_part": "alerts", "mail_from_addr": "legacy@old.example"}) == "alerts@example.com"
assert email.sender_address({"mail_from_addr": "legacy@old.example"}) == "legacy@old.example"
assert email.sender_address({"mail_from_domain": "bad_domain", "mail_from_addr": "invalid"}) == "no-reply@localhost.localdomain"
assert config.sender_address({"mail_from_domain": "example.com", "mail_from_local_part": "alerts"}) == "alerts@example.com"
assert config.sender_address({"mail_from_addr": "legacy@old.example"}) == "legacy@old.example"

sendmail_calls = []


def capture_sendmail(command, input, check, timeout):
    sendmail_calls.append((command, input, check, timeout))


with patch.dict(email.os.environ, {"SLS_WORKER_DEADLINE_EPOCH": ""}), patch.object(email.Path, "is_file", return_value=True), patch.object(email.os, "access", return_value=True), patch.object(
    email.subprocess, "run", side_effect=capture_sendmail
), patch.object(email, "LOGO_PATHS", ()):
    assert email.send_branded_email(
        {
            "mail_from_domain": "example.com",
            "mail_from_local_part": "alerts",
            "mail_from_addr": "legacy@old.example",
            "mail_from_name": "SLS Mass Notification System",
            "system_notification_emails": "recipient@example.net",
        },
        "System test",
        "Test only.",
    )

assert len(sendmail_calls) == 1
command, message_bytes, check, timeout = sendmail_calls[0]
assert command == ["/usr/sbin/sendmail", "-oi", "-t", "-f", "alerts@example.com"]
assert check is True and timeout == email.SENDMAIL_TIMEOUT_SECONDS == 5.0
message = BytesParser(policy=policy.default).parsebytes(message_bytes)
assert parseaddr(message["From"])[1] == "alerts@example.com"
assert message.is_multipart()
assert {part.get_content_type() for part in message.walk()} >= {"text/plain", "text/html"}

with patch.dict(email.os.environ, {"SLS_WORKER_DEADLINE_EPOCH": "1001"}):
    try:
        email.sendmail_timeout(wall_clock=lambda: 1000)
        raise AssertionError("exhausted worker budget allowed a sendmail submission")
    except RuntimeError as exc:
        assert "budget is exhausted" in str(exc)

print("Email sender-domain runtime tests passed.")
