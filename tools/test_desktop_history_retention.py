#!/usr/bin/python3
"""Archive retention preserves active events and the permanent journal lock."""
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import sys
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "slsmassnotifyserver/bin/sls_mass_notify"))
import sls_desktop_history as history


class HistoryRetention(unittest.TestCase):
    def test_storage_cron_can_load_and_run_desktop_retention_in_isolated_python(self):
        runtime=ROOT/'slsmassnotifyserver/bin/sls_mass_notify'
        with tempfile.TemporaryDirectory() as directory:
            data=Path(directory); data.chmod(0o750)
            config=data/'mass-notifications.config';config.write_text('{"log_retention_days":90}');config.chmod(0o640)
            code=('import importlib.util,pathlib;'
                  'spec=importlib.util.spec_from_file_location("storage_fixture",'+repr(str(runtime/'sls_storage_maintenance.py'))+');'
                  'module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);'
                  'module.DATA=pathlib.Path('+repr(str(data))+');'
                  'assert module.prune_desktop_evidence()==0')
            result=subprocess.run(['/usr/bin/python3','-I','-c',code],capture_output=True,text=True,timeout=5,
                env={'PATH':'/usr/bin:/bin','PYTHONPATH':'/untrusted/fixture'})
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(config.read_text(),'{"log_retention_days":90}')

    def test_count_pressure_never_discards_live_events(self):
        now = datetime.now(timezone.utc).isoformat()
        lines = [json.dumps({"id": str(i), "created_at": now}) for i in range(3)]
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(OSError, "unexpired"):
                history.compact(Path(directory) / "events.jsonl", lines, 10000, 2, "2")

    def test_expired_evidence_is_retired_only_after_retention(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            root.chmod(0o750)
            journal = root / "events.jsonl"
            journal.write_text("")
            journal.chmod(0o640)
            lock = Path(str(journal) + ".lock")
            lock.touch(mode=0o640)
            inode = lock.stat().st_ino
            old = {"id": "old-event", "created_at": "2000-01-01T00:00:00+00:00"}
            fresh = {
                "id": "fresh-event",
                "created_at": datetime.now(timezone.utc).isoformat(),
            }
            history.archive(journal, [json.dumps(old), json.dumps(fresh)])
            old_path = history.history_path(journal, "old-event")
            os.utime(old_path, (1, 1))
            self.assertEqual(history.prune(journal, 90), 1)
            self.assertIsNone(history.lookup(journal, "old-event"))
            self.assertEqual(history.lookup(journal, "fresh-event"), fresh)
            self.assertEqual(lock.stat().st_ino, inode)
            self.assertFalse(Path(str(old_path) + ".lock").exists())

    def test_corrupt_old_evidence_is_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            root.chmod(0o750)
            journal = root / "events.jsonl"
            journal.touch(mode=0o640)
            Path(str(journal) + ".lock").touch(mode=0o640)
            event = {"id": "old", "created_at": "2000-01-01T00:00:00+00:00"}
            history.archive(journal, [json.dumps(event)])
            path = history.history_path(journal, "old")
            value = json.loads(path.read_text())
            value["sha256"] = "0" * 64
            path.write_text(json.dumps(value))
            path.chmod(0o640)
            os.utime(path, (1, 1))
            with self.assertRaises(OSError):
                history.prune(journal, 90)
            self.assertTrue(path.exists())


if __name__ == "__main__":
    unittest.main()
