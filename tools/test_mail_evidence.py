#!/usr/bin/env python3
"""Read-only Postfix correlation; synthetic kernel-origin journal records only."""
import importlib.util
import json
from pathlib import Path
import sys
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / "slsmassnotifyserver/bin/sls_mass_notify"))
spec = importlib.util.spec_from_file_location(
    "mail_evidence",
    ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_mail_evidence.py",
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class MailEvidence(unittest.TestCase):
    def setUp(self):
        self.now = 1800000000
        self.programs = {"/fixture/postfix/" + role: role for role in m.ROLES}
        self.message = "<sls-" + ("a" * 32) + "-" + ("b" * 24) + "@example.test>"
        self.address = "private@example.test"

    def entry(self, role, message, offset=0, **changes):
        return {
            "_EXE": "/fixture/postfix/" + role,
            "_UID": "108",
            "_TRANSPORT": "syslog",
            "__REALTIME_TIMESTAMP": str((self.now - 1) * 1000000 + offset),
            "MESSAGE": message,
            **changes,
        }

    def lifecycle(
        self, *, queue="ABCDE12345", message=None, status="sent", dsn="2.0.0", offset=0
    ):
        return [
            self.entry("pickup", f"{queue}: uid=1001 from=<asterisk>", offset + 1),
            self.entry(
                "cleanup", f"{queue}: message-id={message or self.message}", offset + 2
            ),
            self.entry(
                "smtp",
                f"{queue}: to=<{self.address}>, relay=fixture.example, delay=0.1, delays=0/0/0/0, dsn={dsn}, status={status} (fixture)",
                offset + 3,
            ),
            self.entry("qmgr", f"{queue}: removed", offset + 4),
        ]

    def correlate(self, entries, state=None):
        return m.correlate(
            state or m.empty_state(),
            entries,
            programs=self.programs,
            postfix_uid=108,
            asterisk_uid=1001,
            now=self.now,
        )

    def test_same_second_order_precise_stages_and_privacy(self):
        for status, dsn, stage in [
            ("sent", "2.0.0", "relay_accepted"),
            ("deferred", "4.4.1", "deferred"),
            ("bounced", "5.1.1", "bounced"),
        ]:
            result = self.correlate(
                list(reversed(self.lifecycle(status=status, dsn=dsn)))
            )
            self.assertEqual(len(result["messages"]), 1)
            row = next(iter(result["messages"].values()))
            self.assertEqual(row["stage"], stage)
            self.assertEqual(row["dsn"], dsn)
            self.assertEqual(row["message_hash"], m.digest(self.message))
            self.assertEqual(row["recipient_hash"], m.digest(self.address))
            raw = json.dumps(result)
            self.assertNotIn(self.address, raw)
            self.assertNotIn(self.message, raw)
            self.assertNotIn("fixture.example", raw)

    def test_forged_identifiers_forwarded_logs_and_other_uids(self):
        for changes in [
            {"_EXE": "/usr/bin/python3", "SYSLOG_IDENTIFIER": "postfix/smtp"},
            {"_EXE": "/usr/sbin/rsyslogd"},
            {"_UID": "1001"},
            {"_UID": ["108", "0"]},
            {"_TRANSPORT": "stdout"},
        ]:
            entries = [{**row, **changes} for row in self.lifecycle()]
            self.assertFalse(self.correlate(entries)["messages"])
        self.assertFalse(self.correlate(self.lifecycle()[1:])["messages"])
        entries = self.lifecycle()
        entries[0]["MESSAGE"] = "ABCDE12345: uid=1002 from=<asterisk>"
        self.assertFalse(self.correlate(entries)["messages"])
        self.assertFalse(self.correlate(self.lifecycle(dsn="4.0.0"))["messages"])

    def test_queue_reuse_cannot_inherit_prior_message_authority(self):
        previous = self.correlate(self.lifecycle())
        new = "<sls-" + ("c" * 32) + "-" + ("d" * 24) + "@example.test>"
        result = self.correlate(
            self.lifecycle(message=new, status="bounced", dsn="5.1.1", offset=1000),
            previous,
        )
        self.assertEqual(len(result["messages"]), 2)
        rows = {
            row["message_hash"]: row["stage"] for row in result["messages"].values()
        }
        self.assertEqual(rows[m.digest(self.message)], "relay_accepted")
        self.assertEqual(rows[m.digest(new)], "bounced")
        unrelated = self.lifecycle(message="<ordinary@example.test>", offset=2000)
        self.assertEqual(
            self.correlate(unrelated, result)["messages"], result["messages"]
        )

    def test_deferred_can_progress_but_terminal_duplicate_cannot_rewind(self):
        pending = self.lifecycle(status="deferred", dsn="4.4.1")[:3]
        state = self.correlate(pending)
        success = self.lifecycle(offset=2000)[2:]
        done = self.correlate(success, state)
        self.assertEqual(
            next(iter(done["messages"].values()))["stage"], "relay_accepted"
        )
        self.assertEqual(self.correlate(pending, done)["messages"], done["messages"])

    def test_unknown_history_and_ambiguous_json_are_not_accepted(self):
        bad = m.empty_state()
        bad["schema"] = True
        with self.assertRaises(m.EvidenceUnavailable):
            m.validate_state(bad)
        with self.assertRaises(RuntimeError):
            json.loads('{"_UID":"0","_UID":"108"}', object_pairs_hook=m._object)
        entries = self.lifecycle()
        entries[2]["MESSAGE"] += "\nABCDE12345: forged"
        self.assertFalse(self.correlate(entries)["messages"])

    def test_disabled_labs_master_does_not_read_mail_history_or_postfix(self):
        for master in (None, {'enabled': False}, {'enabled': '1'}):
            settings = {'enterprise_integrations': {'enabled': '1', 'mail_evidence': {'enabled': '1'}}}
            if master is not None:
                settings['enterprise_labs'] = master
            with mock.patch.object(m.sys, 'argv', ['sls_mail_evidence.py']), mock.patch.object(m.os, 'geteuid', return_value=0), mock.patch.object(m, 'read_config', return_value=settings), mock.patch.object(m, 'read_json') as ledger, mock.patch.object(m, 'collect') as collect:
                self.assertEqual(m.main(), 0)
                ledger.assert_not_called()
                collect.assert_not_called()

    def test_commands_are_read_only_and_have_output_deadlines(self):
        with self.assertRaises(m.EvidenceUnavailable):
            m.bounded_output(
                [sys.executable, "-I", "-c", 'import sys;sys.stdout.write("x"*10000)'],
                100,
                1,
            )
        with self.assertRaises(m.EvidenceUnavailable):
            m.bounded_output(
                [sys.executable, "-I", "-c", "import time;time.sleep(1)"], 100, 0.05
            )
        with mock.patch.object(
            m, "bounded_output", side_effect=[b"/fixture/postfix\n", b"postfix\n", b""]
        ), mock.patch.object(
            m, "trusted_programs", return_value=self.programs
        ), mock.patch.object(
            m.pwd,
            "getpwnam",
            side_effect=[mock.Mock(pw_uid=108), mock.Mock(pw_uid=1001)],
        ), mock.patch.object(
            m, "inspect_queue", return_value=10
        ):
            result = m.collect(m.empty_state(), self.now)
        self.assertEqual(result["queue_depth"], 10)
        self.assertFalse(result["messages"])


if __name__ == "__main__":
    unittest.main()
