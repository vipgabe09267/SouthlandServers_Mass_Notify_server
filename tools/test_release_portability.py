#!/usr/bin/env python3
"""Release-gate regressions for cross-PBX AMI and SIP NOTIFY behavior."""

import importlib.util
import logging
import pathlib
import sys
from types import SimpleNamespace
from unittest import mock


sys.dont_write_bytecode = True
logging.disable(logging.CRITICAL)
ROOT = pathlib.Path(__file__).resolve().parents[1]
SOURCE = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_notify.py"
CLASS_SOURCE = (ROOT / "slsmassnotifyserver/Slsmassnotifyserver.class.php").read_text(encoding="utf-8")
UNINSTALL_SOURCE = (ROOT / "tools/uninstall_release.sh").read_text(encoding="utf-8")
SPEC = importlib.util.spec_from_file_location("sls_notify_portability_test", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


recording_unlink_guard = "if ($fileOwned && (!is_array($row) || $rowOwned))"
assert CLASS_SOURCE.count(recording_unlink_guard) >= 2
assert recording_unlink_guard in UNINSTALL_SOURCE
wrapper_method = CLASS_SOURCE.split("private function ensurePiperWrapper()", 1)[1].split(
    "private function isSlsOwnedPiperWrapper", 1
)[0]
assert wrapper_method.index("!$this->isSlsOwnedPiperWrapper($wrapper)") < wrapper_method.index(
    "$this->repairPiperRuntimePermissions()"
)
permissions_method = CLASS_SOURCE.split("private function repairPiperRuntimePermissions()", 1)[1].split(
    "private function secureExecutableRuntimeTree", 1
)[0]
assert "$this->isSlsOwnedPiperWrapper('/usr/local/bin/piper')" in permissions_method


assert MODULE.resolve_local_ami_endpoint({"host": "localhost", "port": 5040}) == ("127.0.0.1", 5040)
assert MODULE.resolve_local_ami_endpoint({"host": "::1", "port": "5038"}) == ("127.0.0.1", 5038)
for invalid_ami in (
    {"host": "192.0.2.20", "port": 5038},
    {"host": "localhost", "port": 0},
    {"host": "localhost", "port": "not-a-port"},
):
    try:
        MODULE.resolve_local_ami_endpoint(invalid_ami)
    except RuntimeError:
        pass
    else:
        raise AssertionError(f"unsafe AMI endpoint was accepted: {invalid_ami!r}")


MANAGER_HELP = """
[Syntax]
Action: PJSIPNotify
[Endpoint:] <value>
[URI:] <value>
[Variable:] <value>
"""


without_default = MODULE.parse_pjsip_notify_capabilities(
    MANAGER_HELP,
    " default_outbound_endpoint                  : default_outbound_endpoint\n",
)
assert without_default["endpoint_target"] is True
assert without_default["contact_uri_usable"] is False
assert without_default["routing_mode"] == "endpoint_fanout"

with_named_default = MODULE.parse_pjsip_notify_capabilities(
    MANAGER_HELP,
    " default_outbound_endpoint                  : default_outbound_endpoint\n",
    " Endpoint:  default_outbound_endpoint                         Unavailable\n",
)
assert with_named_default["default_outbound_endpoint"] == "default_outbound_endpoint"
assert with_named_default["contact_uri_usable"] is True

with_default = MODULE.parse_pjsip_notify_capabilities(
    MANAGER_HELP,
    " default_outbound_endpoint                  : dpma_endpoint\n",
    " Endpoint:  dpma_endpoint                                      Unavailable\n",
)
assert with_default["endpoint_target"] is True
assert with_default["contact_uri_usable"] is True
assert with_default["routing_mode"] == "contact_uri"


def fake_capability_cli(command, **_kwargs):
    requested = command[-1]
    if requested == "manager show command PJSIPNotify":
        return SimpleNamespace(stdout=MANAGER_HELP, stderr="", returncode=0)
    if requested == "pjsip show settings":
        return SimpleNamespace(
            stdout=" default_outbound_endpoint                  : dpma_endpoint\n",
            stderr="",
            returncode=0,
        )
    if requested == "pjsip show endpoint dpma_endpoint":
        return SimpleNamespace(
            stdout=" Endpoint:  dpma_endpoint                                      Unavailable\n",
            stderr="",
            returncode=0,
        )
    raise AssertionError(f"unexpected Asterisk capability command: {requested}")


with mock.patch.object(MODULE.subprocess, "run", side_effect=fake_capability_cli):
    orchestrated_capabilities = MODULE.pjsip_notify_capabilities()
assert orchestrated_capabilities["endpoint_target"] is True
assert orchestrated_capabilities["default_outbound_endpoint"] == "dpma_endpoint"
assert orchestrated_capabilities["contact_uri_usable"] is True

ordered_event = {
    "Event": "ContactList",
    "ObjectName": "1000;@contact-hash",
    "Endpoint": "1000",
    "Uri": "sip:1000@192.0.2.10:5061;transport=TLS",
    "UserAgent": "Yealink SIP-T48G",
    "Status": "Reachable",
}
assert MODULE.ami_field(ordered_event, "Endpoint", "AOR", "ObjectName") == "1000"
assert MODULE.contact_uri_for_event(ordered_event, "1000", {}) == "sip:1000@192.0.2.10:5061;transport=TLS"


class InventoryAmi:
    def action(self, fields, complete_event=None):
        return {"Response": "Success"}, [ordered_event, {"Event": "ContactListComplete"}]


inventory = MODULE.get_registered_endpoint_info(InventoryAmi(), resolve_contact_uris=False)
assert list(inventory) == ["1000"]
assert inventory["1000"]["format"] == "yealink"
assert inventory["1000"]["contacts"][0]["contact"].startswith("sip:1000@")


class NotifyAmi:
    def __init__(self, response=None):
        self.response = response or {"Response": "Success", "Message": "NOTIFY sent"}
        self.actions = []

    def action(self, fields, complete_event=None):
        self.actions.append(fields)
        return self.response, []


try:
    MODULE.send_notify(NotifyAmi({"Message": "missing response"}), "1000", "<xml/>")
except RuntimeError:
    pass
else:
    raise AssertionError("an AMI response without explicit Success was accepted")

ami = NotifyAmi()
original_capabilities = MODULE.pjsip_notify_capabilities
try:
    MODULE.pjsip_notify_capabilities = lambda: dict(without_default)
    MODULE.send_notify_batch(
        ami,
        {
            "1000": {
                "format": "yealink",
                "formats": ["yealink"],
                "user_agent": "Yealink SIP-T48G",
                "contacts": [{
                    "contact": "sip:1000@192.0.2.10:5061;transport=TLS",
                    "format": "yealink",
                    "user_agent": "Yealink SIP-T48G",
                }],
            },
        },
        lambda phone_format: "<YealinkIPPhoneTextScreen/>",
        "portability-test",
    )
finally:
    MODULE.pjsip_notify_capabilities = original_capabilities

notify_actions = [action for action in ami.actions if action.get("Action") == "PJSIPNotify"]
assert len(notify_actions) == 1
assert notify_actions[0].get("Endpoint") == "1000"
assert "URI" not in notify_actions[0]

multi_contact_ami = NotifyAmi()
try:
    MODULE.pjsip_notify_capabilities = lambda: dict(without_default)
    delivered = MODULE.send_notify_batch(
        multi_contact_ami,
        {
            "1000": {
                "format": "yealink",
                "formats": ["yealink"],
                "user_agent": "Yealink SIP-T48G",
                "contacts": [
                    {
                        "contact": "sip:1000@192.0.2.10:5061;transport=TLS",
                        "format": "yealink",
                        "user_agent": "Yealink SIP-T48G",
                    },
                    {
                        "contact": "sip:1000@192.0.2.11:5061;transport=TLS",
                        "format": "yealink",
                        "user_agent": "Yealink SIP-T48G",
                    },
                ],
            },
        },
        lambda phone_format: "<YealinkIPPhoneTextScreen/>",
        "multi-contact-endpoint-fanout-test",
    )
finally:
    MODULE.pjsip_notify_capabilities = original_capabilities
notify_actions = [action for action in multi_contact_ami.actions if action.get("Action") == "PJSIPNotify"]
assert delivered == 2
assert len(notify_actions) == 1
assert notify_actions[0].get("Endpoint") == "1000"
assert "URI" not in notify_actions[0]

ami_with_uri_capability = NotifyAmi()
try:
    MODULE.pjsip_notify_capabilities = lambda: dict(with_default)
    MODULE.send_notify_batch(
        ami_with_uri_capability,
        {
            "1000": {
                "format": "yealink",
                "formats": ["yealink"],
                "user_agent": "Yealink SIP-T48G",
                "contacts": [{
                    "contact": "sip:1000@192.0.2.10:5061;transport=TLS",
                    "format": "yealink",
                    "user_agent": "Yealink SIP-T48G",
                }],
            },
        },
        lambda phone_format: "<YealinkIPPhoneTextScreen/>",
        "homogeneous-uri-capability-test",
    )
finally:
    MODULE.pjsip_notify_capabilities = original_capabilities
notify_actions = [action for action in ami_with_uri_capability.actions if action.get("Action") == "PJSIPNotify"]
assert len(notify_actions) == 1
assert notify_actions[0].get("Endpoint") == "1000"
assert "URI" not in notify_actions[0]

successful_mixed_ami = NotifyAmi()
try:
    MODULE.pjsip_notify_capabilities = lambda: dict(with_default)
    delivered = MODULE.send_notify_batch(
        successful_mixed_ami,
        {
            "1000": {
                "format": "yealink",
                "formats": ["yealink", "poly"],
                "user_agent": "mixed",
                "contacts": [
                    {
                        "contact": "sip:1000@192.0.2.10:5061",
                        "format": "yealink",
                        "user_agent": "Yealink",
                    },
                    {
                        "contact": "sip:1000@192.0.2.11:5061",
                        "format": "poly",
                        "user_agent": "Poly",
                    },
                ],
            },
        },
        lambda phone_format: f"<payload vendor='{phone_format}'/>",
        "mixed-uri-success-test",
    )
finally:
    MODULE.pjsip_notify_capabilities = original_capabilities
mixed_actions = [action for action in successful_mixed_ami.actions if action.get("Action") == "PJSIPNotify"]
assert delivered == 2
assert len(mixed_actions) == 2
assert {action.get("URI") for action in mixed_actions} == {
    "sip:1000@192.0.2.10:5061",
    "sip:1000@192.0.2.11:5061",
}
assert all("Endpoint" not in action for action in mixed_actions)
assert any("vendor='yealink'" in "\n".join(action.get("Variable", [])) for action in mixed_actions)
assert any("vendor='poly'" in "\n".join(action.get("Variable", [])) for action in mixed_actions)

mixed_ami = NotifyAmi()
try:
    MODULE.pjsip_notify_capabilities = lambda: dict(without_default)
    try:
        MODULE.send_notify_batch(
            mixed_ami,
            {
                "1000": {
                    "format": "yealink",
                    "formats": ["yealink", "poly"],
                    "user_agent": "mixed",
                    "contacts": [
                        {"contact": "sip:1000@192.0.2.10:5061", "format": "yealink", "user_agent": "Yealink"},
                        {"contact": "sip:1000@192.0.2.11:5061", "format": "poly", "user_agent": "Poly"},
                    ],
                },
            },
            lambda phone_format: f"<{phone_format}/>",
            "mixed-portability-test",
        )
    except RuntimeError as exc:
        assert "mixed phone formats" in str(exc)
    else:
        raise AssertionError("mixed-format endpoint fan-out was not rejected")
finally:
    MODULE.pjsip_notify_capabilities = original_capabilities
assert not mixed_ami.actions

for incomplete_contacts in (
    [
        {"contact": "sip:1000@192.0.2.10:5061", "format": "yealink", "user_agent": "Yealink"},
        {"contact": "", "format": "poly", "user_agent": "Poly"},
    ],
    [
        {"contact": "", "format": "yealink", "user_agent": "Yealink"},
        {"contact": "", "format": "poly", "user_agent": "Poly"},
    ],
):
    incomplete_ami = NotifyAmi()
    try:
        MODULE.pjsip_notify_capabilities = lambda: dict(with_default)
        try:
            MODULE.send_notify_batch(
                incomplete_ami,
                {
                    "1000": {
                        "format": "yealink",
                        "formats": ["yealink", "poly"],
                        "user_agent": "mixed",
                        "contacts": incomplete_contacts,
                    },
                },
                lambda phone_format: f"<{phone_format}/>",
                "mixed-missing-uri-test",
            )
        except RuntimeError as exc:
            assert "distinct contact URI for every registration" in str(exc)
        else:
            raise AssertionError("mixed-format registrations with missing contact URIs were not rejected")
    finally:
        MODULE.pjsip_notify_capabilities = original_capabilities
    assert not incomplete_ami.actions

uri_failure_ami = NotifyAmi({"Response": "Error", "Message": "URI delivery failed"})
try:
    MODULE.pjsip_notify_capabilities = lambda: dict(with_default)
    try:
        MODULE.send_notify_batch(
            uri_failure_ami,
            {
                "1000": {
                    "format": "yealink",
                    "formats": ["yealink", "poly"],
                    "user_agent": "mixed",
                    "contacts": [
                        {"contact": "sip:1000@192.0.2.10:5061", "format": "yealink", "user_agent": "Yealink"},
                        {"contact": "sip:1000@192.0.2.11:5061", "format": "poly", "user_agent": "Poly"},
                    ],
                },
            },
            lambda phone_format: f"<{phone_format}/>",
            "mixed-uri-failure-test",
        )
    except RuntimeError:
        pass
    else:
        raise AssertionError("mixed-format URI delivery failure was accepted")
finally:
    MODULE.pjsip_notify_capabilities = original_capabilities
assert uri_failure_ami.actions
assert all(action.get("URI") and "Endpoint" not in action for action in uri_failure_ami.actions)

assert MODULE.ami_response_is_empty_contact_inventory({
    "Response": "Error",
    "Message": "No Contacts found",
})
assert not MODULE.ami_response_is_empty_contact_inventory({
    "Response": "Error",
    "Message": "Permission denied",
})
assert MODULE.ami_response_is_missing_probe_endpoint({
    "Response": "Error",
    "Message": "Unable to retrieve endpoint __sls_capability_probe__",
})
assert not MODULE.ami_response_is_missing_probe_endpoint({
    "Response": "Error",
    "Message": "Permission denied",
})

print("Cross-PBX SIP NOTIFY portability regressions passed.")
