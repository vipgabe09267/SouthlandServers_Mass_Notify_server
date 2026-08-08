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

assert email.sender_address({"mail_from_domain": "example.com", "mail_from_addr": "legacy@old.example"}) == "no-reply@example.com"
assert email.sender_address({"mail_from_addr": "legacy@old.example"}) == "legacy@old.example"
assert email.sender_address({"mail_from_domain": "bad_domain", "mail_from_addr": "invalid"}) == "no-reply@localhost.localdomain"
assert config.sender_address({"mail_from_domain": "example.com"}) == "no-reply@example.com"
assert config.sender_address({"mail_from_addr": "legacy@old.example"}) == "legacy@old.example"

sendmail_calls = []


def capture_sendmail(command, input, check, timeout):
    sendmail_calls.append((command, input, check, timeout))


with patch.object(email.Path, "is_file", return_value=True), patch.object(
    email.subprocess, "run", side_effect=capture_sendmail
), patch.object(email, "LOGO_PATHS", ()):
    assert email.send_branded_email(
        {
            "mail_from_domain": "example.com",
            "mail_from_addr": "legacy@old.example",
            "mail_from_name": "SLS Mass Notification System",
            "mail_to": "recipient@example.net",
        },
        "System test",
        "Test only.",
    )

assert len(sendmail_calls) == 1
command, message_bytes, check, timeout = sendmail_calls[0]
assert command == ["/usr/sbin/sendmail", "-t", "-f", "no-reply@example.com"]
assert check is True and timeout == 30
message = BytesParser(policy=policy.default).parsebytes(message_bytes)
assert parseaddr(message["From"])[1] == "no-reply@example.com"
assert message.is_multipart()
assert {part.get_content_type() for part in message.walk()} >= {"text/plain", "text/html"}

print("Email sender-domain runtime tests passed.")
