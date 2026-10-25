#!/usr/bin/python3
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import types
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "support_snapshot_fixture",
    ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_support_snapshot.py",
)
snapshot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(snapshot)


class SupportSnapshotTests(unittest.TestCase):
    def test_postfix_monitor_never_submits_mail_and_preserves_unknown_results(self):
        with patch.object(snapshot.Path,'lstat',return_value=types.SimpleNamespace(st_mode=0o100755,st_uid=0)), \
             patch.object(snapshot.subprocess,'run',return_value=types.SimpleNamespace(returncode=0)) as run, \
             patch('sls_mail_admission.inspect_queue',return_value=7):
            result=snapshot.postfix_health()
        self.assertEqual(result,{'running':True,'queue_available':True,'queue_depth':7,'queue_limit':200,'queue_at_limit':False,'code':'postfix_running'})
        self.assertEqual(run.call_args.args[0],['/usr/sbin/postfix','status'])
        self.assertNotIn('shell',run.call_args.kwargs)
        with patch.object(snapshot.Path,'lstat',return_value=types.SimpleNamespace(st_mode=0o100755,st_uid=0)), \
             patch.object(snapshot.subprocess,'run',side_effect=subprocess.TimeoutExpired('postfix',3)):
            result=snapshot.postfix_health()
        self.assertFalse(result['queue_available'])
        self.assertIsNone(result['queue_depth'])

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-support-snapshot-")
        self.directory = Path(self.temporary.name)
        self.path = self.directory / "audit.jsonl"

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, rows):
        self.path.write_text("".join(json.dumps(row) + "\n" for row in rows))
        self.path.chmod(0o640)

    def test_sampled_latency_and_last_use_are_bounded_and_do_not_copy_contents(self):
        rows = [
            {
                "created_at": "2027-01-15T08:00:00+00:00",
                "status": 200,
                "duration_ms": value,
                "credential_id": "api_" + "a" * 24,
                "message": "PRIVATE CONTENT",
            }
            for value in (10, 20, 2000)
        ]
        self.write(rows)
        result = snapshot.audit_aggregates(self.path, 1800000000)
        self.assertEqual(result["sample_count"], 3)
        self.assertEqual(result["duration_p95_ms"], 2000)
        self.assertEqual(result["credential_usage"], {"api_" + "a" * 24: 1800000000})
        self.assertNotIn("PRIVATE", json.dumps(result))

    def test_missing_audit_does_not_invent_latency_or_lifetime_usage(self):
        result = snapshot.audit_aggregates(self.path, 1800000000)
        self.assertEqual(result["sample_count"], 0)
        self.assertNotIn("duration_p95_ms", result)

    def test_symlinks_and_invalid_json_are_rejected(self):
        target = self.directory / "target"
        target.write_text("private")
        self.path.symlink_to(target)
        descriptors=len(os.listdir('/proc/self/fd'))
        with self.assertRaises(OSError):
            snapshot.audit_aggregates(self.path, 1800000000)
        self.assertEqual(len(os.listdir('/proc/self/fd')),descriptors)
        self.path.unlink()
        self.path.write_text('{"created_at":"first","created_at":"second"}\n')
        self.path.chmod(0o640)
        with self.assertRaises((ValueError, RuntimeError)):
            snapshot.audit_aggregates(self.path, 1800000000)
        self.assertEqual(target.read_text(), "private")

    def test_collect_counts_recovery_phases_without_exporting_transaction_paths(self):
        installer = self.directory / "installer"
        installer.mkdir(mode=0o700)
        transaction = installer / ("transaction-" + "a" * 24)
        transaction.mkdir(mode=0o700)
        (transaction / "transaction.json").write_text(
            json.dumps(
                {
                    "schema": 1,
                    "directory": str(transaction),
                    "phase": "prepared",
                    "created_at": 1,
                    "updated_at": 2,
                    "settings": {},
                }
            )
        )
        (transaction / "transaction.json").chmod(0o600)
        with patch.object(snapshot, "probe_compute", return_value=(2.0, 4294967296)):
            result = snapshot.collect(self.directory, installer, 1800000000)
        self.assertEqual(
            result["installer_transactions"],
            {"counts": {"prepared": 1}, "complete": True},
        )
        self.assertNotIn(str(transaction), json.dumps(result))


if __name__ == "__main__":
    unittest.main()
