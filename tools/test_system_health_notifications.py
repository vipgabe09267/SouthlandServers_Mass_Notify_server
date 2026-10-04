#!/usr/bin/env python3
"""Isolated worker/storage system fault checks; all email senders are fakes."""

from datetime import datetime, timezone
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
import stat
from unittest import mock


sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "slsmassnotifyserver/bin/sls_mass_notify"
sys.path.insert(0, str(RUNTIME))
SPEC = importlib.util.spec_from_file_location("sls_health_notification_fixture", RUNTIME / "sls_system_notifications.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
NOW = 1_800_000_000


def worker(**updates):
    value = {"ok": True, "checked_at": datetime.fromtimestamp(NOW, timezone.utc).isoformat(),
             "bootstrap": True, "module_loaded": True, "storage_writable": True}
    value.update(updates)
    return value


def storage(**updates):
    value = {"checked_at": NOW, "free_bytes": 1024 * 1024, "queue_errors": 0,
             "queue_scan_incomplete": False, "audit_failed_records": 0, "audit_at_capacity": False}
    value.update(updates)
    return value


class HealthSnapshots(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sls-health-faults-")
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)

    def test_media_cache_faults_clear_with_current_measurements(self):
        for flag in ('media_over_budget', 'media_scan_incomplete'):
            self.assertIn('storage', MODULE.collect_health_faults(worker(), storage(**{flag: True}), now=NOW))
            self.assertNotIn('storage', MODULE.collect_health_faults(worker(), storage(**{flag: False}), now=NOW))

    def test_recovered_audit_history_is_not_a_current_fault(self):
        self.assertNotIn("storage", MODULE.collect_health_faults(worker(),
            storage(audit_failed_records=9, audit_failure_code="audit_storage_busy", audit_fault_active=False), now=NOW))
        self.assertIn("storage", MODULE.collect_health_faults(worker(),
            storage(audit_failed_records=9, audit_fault_active=True), now=NOW))
        self.assertIn("storage", MODULE.collect_health_faults(worker(),
            storage(audit_forwarding_active=True), now=NOW))
        self.assertNotIn("storage", MODULE.collect_health_faults(worker(),
            storage(audit_forwarding_active=False, audit_forwarding_failed_records=7), now=NOW))

    def test_actual_isolated_cli_imports_email_without_executing_main_or_writing_bytecode(self):
        script=(RUNTIME/'sls_system_notifications.py').read_text().replace('    raise SystemExit(main())','    raise SystemExit(37)')
        entry=self.base/'sls_system_notifications.py';entry.write_text(script)
        (self.base/'sls_branded_email.py').write_bytes((RUNTIME/'sls_branded_email.py').read_bytes())
        (self.base/'sls_config_crypto.py').write_bytes((RUNTIME/'sls_config_crypto.py').read_bytes())
        (self.base/'sls_cluster_guard.py').write_bytes((RUNTIME/'sls_cluster_guard.py').read_bytes())
        env=dict(os.environ);env.pop('PYTHONDONTWRITEBYTECODE',None)
        result=subprocess.run(['/usr/bin/python3','-I',str(entry)],env=env,capture_output=True,text=True,timeout=3)
        self.assertEqual(result.returncode,37,result.stderr)
        self.assertFalse((self.base/'__pycache__').exists())
    def test_notification_lock_rejects_links_and_special_files_without_changing_victim(self):
        victim = self.base / "victim"
        victim.write_bytes(b"private fixture")
        victim.chmod(0o600)
        before = victim.stat()
        linked = self.base / "hardlink"
        os.link(victim, linked)
        symlink = self.base / "symlink"
        symlink.symlink_to(victim)
        fifo = self.base / "fifo"
        os.mkfifo(fifo, 0o600)
        parent_link = self.base / "linked-parent"
        parent_link.symlink_to(self.base, target_is_directory=True)
        for path in (linked, symlink, fifo, parent_link / "lock"):
            with self.subTest(path=path.name):
                with self.assertRaises((RuntimeError, OSError)):
                    MODULE._open_notification_lock(path)
        after = victim.stat()
        self.assertEqual((before.st_uid, before.st_gid, before.st_mode), (after.st_uid, after.st_gid, after.st_mode))
        self.assertEqual(victim.read_bytes(), b"private fixture")

    def test_notification_lock_is_exclusive_and_bounded(self):
        path = self.base / "lock"
        descriptor = MODULE._open_notification_lock(path)
        try:
            self.assertEqual(stat.S_IMODE(os.fstat(descriptor).st_mode), 0o640)
            start = time.monotonic()
            with self.assertRaisesRegex(RuntimeError, "another system notification"):
                MODULE._open_notification_lock(path)
            self.assertLess(time.monotonic() - start, 1)
        finally:
            os.close(descriptor)
        descriptor = MODULE._open_notification_lock(path)
        os.close(descriptor)

    def test_notification_lock_rejects_path_replacement_before_chmod(self):
        path = self.base / "lock"
        path.write_bytes(b"original")
        path.chmod(0o600)
        original_stat = MODULE.os.stat
        def replaced(candidate, *args, **kwargs):
            if candidate == "lock" and "dir_fd" in kwargs:
                path.rename(self.base / "held")
                path.write_bytes(b"replacement")
                path.chmod(0o600)
            return original_stat(candidate, *args, **kwargs)
        with mock.patch.object(MODULE.os, "stat", side_effect=replaced):
            with self.assertRaisesRegex(RuntimeError, "changed during open"):
                MODULE._open_notification_lock(path)
        self.assertEqual(stat.S_IMODE((self.base / "held").stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)

    def test_fresh_healthy_reports_do_not_generate_faults(self):
        self.assertEqual(MODULE.collect_health_faults(worker(), storage(), now=NOW), {})
        # Healthy historical worker schema need not include the newer optional fields.
        self.assertEqual(MODULE.collect_health_faults({"ok": True, "checked_at": worker()["checked_at"]}, storage(), now=NOW), {})
        path = self.base / "worker.json"
        path.write_text(json.dumps(worker()))
        self.assertEqual(MODULE._read_health_snapshot(path), (worker(), ""))

    def test_current_ui_staleness_thresholds_and_invalid_clock(self):
        self.assertEqual(MODULE.collect_health_faults(worker(), storage(), now=NOW + 300), {})
        faults = MODULE.collect_health_faults(worker(), storage(), now=NOW + 301)
        self.assertEqual(set(faults), {"storage"})
        self.assertIn("stale", faults["storage"]["message"])
        self.assertEqual(set(MODULE.collect_health_faults(worker(), storage(checked_at=NOW + 599), now=NOW + 599)), set())
        faults = MODULE.collect_health_faults(worker(), storage(checked_at=NOW + 600), now=NOW + 600)
        self.assertEqual(set(faults), {"announcement_worker"})
        for timestamp in ("2026-09-20T00:00:00", "secret raw timestamp", None, 1):
            self.assertIn("announcement_worker", MODULE.collect_health_faults(worker(checked_at=timestamp), storage(), now=NOW))
        self.assertEqual(set(MODULE.collect_health_faults(worker(), storage(checked_at=NOW + 61), now=NOW)), {"storage"})

    def test_fault_sources_use_fixed_nonsecret_messages_and_stable_ids(self):
        secret = "PRIVATE-CREDENTIAL-MUST-NOT-APPEAR"
        faults = MODULE.collect_health_faults(worker(ok=False, failure_category={"token": secret}),
            storage(queue_errors=1, audit_failure_code=secret), now=NOW)
        self.assertEqual(set(faults), {"announcement_worker", "storage"})
        self.assertNotIn(secret, json.dumps(faults))
        later = MODULE.collect_health_faults(worker(ok=False, failure_category="worker_bootstrap_failed"),
            storage(queue_errors=19, audit_failed_records=4), now=NOW + 60)
        self.assertEqual({key: value["fingerprint"] for key, value in faults.items()},
                         {key: value["fingerprint"] for key, value in later.items()})
        self.assertIn("FreePBX", later["announcement_worker"]["message"])

    def test_storage_fault_counters_and_flags_are_explicit(self):
        for field, value in (("free_bytes", 0), ("queue_errors", 1), ("queue_scan_incomplete", True),
                             ("expired_external", 1), ("failed_weather", 1), ("uncertain_weather", 1),
                             ("expired_weather", 1), ("weather_deadline_misses", 1),
                             ("audit_at_capacity", True), ("audit_failed_records", 1)):
            with self.subTest(field=field):
                self.assertEqual(set(MODULE.collect_health_faults(worker(), storage(**{field: value}), now=NOW)), {"storage"})
        for field, value in (("free_bytes", "0"), ("queue_errors", True), ("failed_weather", -1),
                             ("queue_scan_incomplete", "false"), ("audit_at_capacity", 0)):
            with self.subTest(field=field):
                fault = MODULE.collect_health_faults(worker(), storage(**{field: value}), now=NOW)["storage"]
                self.assertIn("invalid", fault["message"])
        missing = storage()
        del missing["free_bytes"]
        self.assertIn("cannot confirm", MODULE.collect_health_faults(worker(), missing, now=NOW)["storage"]["message"])

    def test_missing_corrupt_oversized_and_deep_health_is_bounded(self):
        path = self.base / "health.json"
        self.assertEqual(MODULE._read_health_snapshot(path), (None, "missing"))
        for raw in (b"", b"[]", b"{broken", b"\xff", b"[" * 2000 + b"]" * 2000, b" " * (MODULE.MAX_HEALTH_BYTES + 1)):
            with self.subTest(size=len(raw)):
                path.write_bytes(raw)
                data, error = MODULE._read_health_snapshot(path)
                self.assertIsNone(data)
                self.assertTrue(error)
        # A growth race cannot bypass the read bound after the size check.
        path.write_bytes(b" " * (MODULE.MAX_HEALTH_BYTES + 1))
        actual_fstat = os.fstat
        def understated_size(fd):
            fields = list(actual_fstat(fd))
            fields[6] = 1
            return os.stat_result(fields)
        with mock.patch.object(MODULE.os, "fstat", side_effect=understated_size):
            self.assertEqual(MODULE._read_health_snapshot(path), (None, "oversized"))

    def test_health_rejects_hardlinks_and_symlink_parents(self):
        path = self.base / "health.json"
        path.write_text(json.dumps(storage()))
        link = self.base / "hardlink.json"
        os.link(path, link)
        self.assertIsNone(MODULE._read_health_snapshot(path)[0])
        link.unlink()
        link.symlink_to(path)
        self.assertIsNone(MODULE._read_health_snapshot(link)[0])
        parent = self.base / "linked-parent"
        parent.symlink_to(self.base, target_is_directory=True)
        self.assertIsNone(MODULE._read_health_snapshot(parent / "health.json")[0])

    def test_fifo_and_contended_snapshots_cannot_block_notification_worker(self):
        fifo = self.base / "fifo"
        os.mkfifo(fifo)
        command = '''import importlib.util, sys
sys.path.insert(0, sys.argv[1])
spec = importlib.util.spec_from_file_location("fixture", sys.argv[1] + "/sls_system_notifications.py")
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
from pathlib import Path
assert m._read_health_snapshot(Path(sys.argv[2]))[0] is None
assert m._read_json(Path(sys.argv[2]), tolerate_corrupt=True) is None
'''
        result = subprocess.run([sys.executable, "-B", "-c", command, str(RUNTIME), str(fifo)],
                                capture_output=True, text=True, timeout=3)
        self.assertEqual(result.returncode, 0, result.stderr)
        path = self.base / "locked.json"
        path.write_text(json.dumps(storage()))
        with path.open("rb") as handle:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            start = time.monotonic()
            self.assertEqual(MODULE._read_health_snapshot(path), (None, "busy"))
            self.assertIsNone(MODULE._read_json(path, tolerate_corrupt=True))
            self.assertLess(time.monotonic() - start, 0.2)


class HealthEmailPolicy(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sls-health-email-policy-")
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.state = self.base / "state.json"
        self.calls = []
        self.config = {"system_notification_emails": "operator@example.invalid"}

    def sender(self, *args, **kwargs):
        self.calls.append((args, kwargs))
        return True

    def process(self, faults, now=NOW, **kwargs):
        return MODULE.process_faults(self.config, faults, self.state, sender=self.sender, now=now, **kwargs)

    def test_health_emails_are_opt_in_deduplicated_and_allow_recurrence(self):
        faults = MODULE.collect_health_faults(worker(ok=False), storage(audit_failed_records=1), now=NOW)
        result = MODULE.process_faults({"mail_to": "legacy@example.invalid"}, faults, self.state, sender=self.sender, now=NOW)
        self.assertEqual(result, {"sent": 0, "active": 2})
        self.assertEqual(self.calls, [])
        self.assertEqual(self.process(faults)["sent"], 2)
        updated = MODULE.collect_health_faults(worker(ok=False), storage(audit_failed_records=9), now=NOW + 60)
        self.assertEqual(self.process(updated, NOW + 60)["sent"], 0)
        self.assertEqual(len(self.calls), 2)
        self.process({}, NOW + 120)
        self.assertEqual(self.process(faults, NOW + 180)["sent"], 0)
        self.process({}, NOW + 240)
        self.assertEqual(self.process(faults, NOW + 86401)["sent"], 2)

    def test_failed_mail_retries_only_after_existing_delay(self):
        faults = MODULE.collect_health_faults(worker(ok=False), storage(), now=NOW)
        attempted = []
        def failed_sender(*args, **kwargs):
            attempted.append(True)
            return False
        with self.assertRaisesRegex(RuntimeError, "not accepted"):
            MODULE.process_faults(self.config, faults, self.state, sender=failed_sender, now=NOW)
        self.assertEqual(self.process(faults, NOW + MODULE.RETRY_SECONDS - 1)["sent"], 0)
        self.assertEqual(self.calls, [])
        self.assertEqual(self.process(faults, NOW + MODULE.RETRY_SECONDS)["sent"], 1)
        self.assertEqual(len(attempted), 1)

    def test_missing_weather_status_preserves_dedup_while_health_sends(self):
        weather = dict([MODULE._candidate("weather", "poll", "fixture weather unavailable", "fixture")])
        self.assertEqual(self.process(weather)["sent"], 1)
        missing_status = MODULE._read_json(self.base / "missing.json", tolerate_corrupt=True)
        self.assertIsNone(missing_status)
        health = MODULE.collect_health_faults(worker(ok=False), storage(), now=NOW)
        self.assertEqual(self.process(health, NOW + 60, preserve_status_faults=missing_status is None), {"sent": 1, "active": 2})
        self.assertEqual(self.process({**weather, **health}, NOW + 120)["sent"], 0)
        self.assertEqual(len(self.calls), 2)

    def test_preserved_status_cannot_grow_state_above_existing_bound(self):
        self.state.write_text(json.dumps({"version": 1, "active": {"lightning_" + str(i): "a" * 64
                              for i in range(MODULE.MAX_ACTIVE_FAULTS + 1)}}))
        before = self.state.read_bytes()
        with self.assertRaisesRegex(RuntimeError, "exceeds its limit"):
            self.process({}, preserve_status_faults=True)
        self.assertEqual(self.state.read_bytes(), before)
        self.assertEqual(self.calls, [])

    def test_main_checks_independent_health_when_weather_json_is_corrupt(self):
        config = self.base / "config.json"
        config.write_text(json.dumps(self.config))
        config.chmod(0o640)
        weather_path = self.base / "weather.json"
        weather_path.write_text("{partial")
        worker_path = self.base / "worker.json"
        worker_path.write_text(json.dumps(worker(ok=False)))
        storage_path = self.base / "storage.json"
        storage_path.write_text(json.dumps(storage(queue_errors=1)))
        observed = []
        def fake_process(config, faults, state_path, **kwargs):
            observed.append((faults, kwargs))
            return {"sent": 0, "active": len(faults)}
        with mock.patch.multiple(MODULE, CONFIG_FILE=config, STATUS_FILE=weather_path,
                WORKER_HEALTH_FILE=worker_path, STORAGE_HEALTH_FILE=storage_path,
                INSTALL_FAILURE_FILE=self.base / "install.json", UPDATE_PROGRESS_FILE=self.base / "update.json",
                MAINTENANCE_PROGRESS_FILE=self.base / "maintenance.json", LOCK_FILE=self.base / "mail.lock",
                STATE_FILE=self.state), mock.patch.object(MODULE, "process_faults", side_effect=fake_process), \
                mock.patch.object(MODULE.time, "time", return_value=NOW):
            self.assertEqual(MODULE.main(), 0)
        self.assertEqual(set(observed[0][0]), {"announcement_worker", "storage"})
        self.assertTrue(observed[0][1]["preserve_status_faults"])


if __name__ == "__main__":
    unittest.main()
