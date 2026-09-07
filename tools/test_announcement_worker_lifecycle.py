#!/usr/bin/env python3
"""Offline PHP subprocess lifecycle tests; no production bootstrap or deliveries."""

import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
CANDIDATE = Path(os.environ.get("SLS_ANNOUNCEMENT_CANDIDATE_DIR", ROOT / "slsmassnotifyserver"))
JOB_ID = "job_" + "a" * 32
SECRET = "fixture-secret-never-persist-7e34f1"


class WorkerLifecycleTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="sls-worker-lifecycle-")
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.data = self.folder / "jobs"
        self.data.mkdir(mode=0o750)
        self.bootstrap = self.folder / "fixture_freepbx.php"
        helper_path = CANDIDATE / "sls_announcement_jobs.php"
        worker_path = CANDIDATE / "sls_mass_notify_announcement_worker.php"
        if not helper_path.is_file():
            helper_path = CANDIDATE / "bin/sls_mass_notify/sls_announcement_jobs.php"
        if not worker_path.is_file():
            worker_path = CANDIDATE / "bin/sls_mass_notify_announcement_worker.php"
        helper = helper_path.read_text()
        helper, replaced = re.subn(
            r"const DATA = '[^']+';", "const DATA = " + self.php_string(str(self.data)) + ";", helper, count=1,
        )
        self.assertEqual(replaced, 1, "Fixture must replace the production DATA path")
        self.helper = self.folder / "sls_announcement_jobs.php"
        self.helper.write_text(helper)
        worker = worker_path.read_text()
        self.assertIn("require '/etc/freepbx.conf';", worker)
        worker = worker.replace("require '/etc/freepbx.conf';", "require " + self.php_string(str(self.bootstrap)) + ";")
        self.assertNotIn("/etc/freepbx.conf", worker)
        # Keep the isolated fixture files owned by this test's account. Production
        # privilege dropping is not exercised, and no runtime override is added.
        drop_condition = "if (function_exists('posix_geteuid') && posix_geteuid() === 0) {"
        self.assertEqual(worker.count(drop_condition), 1)
        worker = worker.replace(drop_condition, "if (false) {", 1)
        self.worker = self.folder / "sls_mass_notify_announcement_worker.php"
        self.worker.write_text(worker)
        self.bootstrap.write_text("<?php\n")

    @staticmethod
    def php_string(value):
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

    def php(self, source):
        result = subprocess.run(
            ["php", "-d", "display_errors=0", "-r", "require " + self.php_string(str(self.helper)) + ";" + source],
            cwd=self.folder, capture_output=True, text=True, timeout=8, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def seed(self, state="worker_starting", age=0, job_id=JOB_ID):
        stamp = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time() - age))
        job = {"id": job_id, "state": state, "created_at": stamp, "startup_attempted_at": stamp,
               "request": {"phones": [], "desktops": [], "webhooks": []}, "receipts": []}
        self.php("$store = new SlsAnnouncementJobStore(); $store->write(json_decode(" + self.php_string(json.dumps(job)) + ", true));")
        return job

    def read(self, job_id=JOB_ID):
        return json.loads((self.data / (job_id + ".json")).read_text())

    def run_worker(self, *arguments):
        return subprocess.run(
            ["php", "-d", "display_errors=0", str(self.worker), *arguments],
            cwd=self.folder, capture_output=True, text=True, timeout=8, check=False,
        )

    def module(self, action="complete", module_action="normal"):
        # This module only writes fixture job state; it has no delivery code.
        source = """<?php
class FreePBX {
    public static function Slsmassnotifyserver() {
        MODULE_ACTION
        return new FixtureAnnouncementModule();
    }
}
class FixtureAnnouncementModule {
    public function processAnnouncementJobs($id) {
        $store = new SlsAnnouncementJobStore();
        $lock = $store->lock($id);
        if (!$lock) { return false; }
        $job = $store->read($id);
        $job['state'] = 'running'; $job['started_at'] = gmdate('c');
        $store->write($job);
        PROCESS_ACTION
        $job['state'] = 'complete'; $job['finished_at'] = gmdate('c');
        $job['message'] = 'Fixture submission finished.';
        $job['receipts'] = [['channel' => 'sip_notify', 'state' => 'submitted_to_asterisk', 'target' => 'fixture']];
        $store->write($job); SlsAnnouncementJobStore::unlock($lock);
        return true;
    }
}
"""
        module_actions = {
            "normal": "", "null": "return null;",
            "throw": "throw new RuntimeException(" + self.php_string(SECRET) + ");",
        }
        actions = {
            "complete": "", "throw": "throw new RuntimeException(" + self.php_string(SECRET) + ");",
            "exit_zero": "fwrite(STDERR, " + self.php_string(SECRET) + "); exit(0);",
            "exit_nonzero": "fwrite(STDERR, " + self.php_string(SECRET) + "); exit(9);",
            "return_false": "return false;", "return_true_without_finish": "return true;",
            "hang": "while (true) { usleep(10000); }",
        }
        self.bootstrap.write_text(source.replace("MODULE_ACTION", module_actions[module_action]).replace("PROCESS_ACTION", actions[action]))

    def assert_failed(self, result, category, uncertain=False):
        job = self.read()
        self.assertEqual(job["state"], "failed", result.stdout + result.stderr)
        self.assertEqual(job["failure_category"], category)
        self.assertEqual(job.get("submission_uncertain", False), uncertain)
        self.assertNotEqual(result.returncode, 0, "A supervisor must not report success after recording job failure")
        self.assertFalse((self.data / ("pending_" + JOB_ID + ".mark")).exists())
        for path in self.data.iterdir():
            if path.is_file():
                self.assertNotIn(SECRET.encode(), path.read_bytes(), str(path))
        self.assertNotIn(SECRET, result.stdout + result.stderr)

    def test_bootstrap_throw_is_terminal_and_sanitized(self):
        self.seed()
        self.bootstrap.write_text("<?php throw new RuntimeException(" + self.php_string(SECRET) + ");")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_bootstrap_failed")

    def test_bootstrap_exit_zero_is_not_success(self):
        self.seed()
        self.bootstrap.write_text("<?php fwrite(STDERR, " + self.php_string(SECRET) + "); exit(0);")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_bootstrap_failed")

    def test_bootstrap_nonzero_exit_is_terminal(self):
        self.seed()
        self.bootstrap.write_text("<?php exit(7);")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_bootstrap_failed")

    def test_module_load_failure_is_terminal(self):
        for mode in ("null", "throw"):
            with self.subTest(mode=mode):
                self.seed()
                self.module(module_action=mode)
                self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_module_load_failed")

    def test_processing_throw_is_uncertain_and_terminal(self):
        self.seed(); self.module("throw")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_runtime_failed", uncertain=True)

    def test_processing_early_exit_zero_is_not_success(self):
        self.seed(); self.module("exit_zero")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_runtime_failed", uncertain=True)

    def test_processing_nonzero_exit_is_uncertain_and_terminal(self):
        self.seed(); self.module("exit_nonzero")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_runtime_failed", uncertain=True)

    def test_false_processing_result_cannot_leave_running(self):
        self.seed(); self.module("return_false")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_runtime_failed", uncertain=True)

    def test_true_processing_result_without_terminal_job_is_not_success(self):
        self.seed(); self.module("return_true_without_finish")
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_runtime_failed", uncertain=True)

    def test_worker_claims_then_completes_with_submitted_receipt(self):
        self.seed(); self.module()
        result = self.run_worker("--supervise", JOB_ID)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        job = self.read()
        self.assertEqual(job["state"], "complete")
        self.assertTrue(job["started_at"])
        self.assertTrue(job["finished_at"])
        self.assertEqual(job["receipts"][0]["state"], "submitted_to_asterisk")
        self.assertFalse((self.data / ("pending_" + JOB_ID + ".mark")).exists())

    def test_supervisor_timeout_leaves_uncertain_terminal_failure(self):
        self.seed(); self.module("hang")
        worker = self.worker.read_text()
        command = "['/usr/bin/timeout', '--kill-after=5s', '900',"
        self.assertIn(command, worker)
        # Shorten only the fixture's real process timeout; exercise the existing
        # production timeout exit classification without waiting fifteen minutes.
        self.worker.write_text(worker.replace(command, "['/usr/bin/timeout', '--kill-after=0.1s', '0.2s',", 1))
        self.assert_failed(self.run_worker("--supervise", JOB_ID), "worker_timeout", uncertain=True)

    def test_reconcile_stale_starting_and_abandoned_running(self):
        for state, age, category, uncertain in (
            ("worker_starting", 90, "worker_start_failed", False),
            ("queued", 90, "worker_start_failed", False),
            ("queued", 1000, "job_expired", False),
            ("running", 0, "worker_runtime_failed", True),
        ):
            with self.subTest(state=state, age=age):
                self.seed(state, age)
                result = self.run_worker("--reconcile")
                self.assertEqual(result.returncode, 0, result.stderr)
                job = self.read()
                self.assertEqual(job["state"], "expired" if category == "job_expired" else "failed")
                self.assertEqual(job["failure_category"], category)
                self.assertEqual(job.get("submission_uncertain", False), uncertain)

    def test_reconcile_preserves_fresh_start_and_active_flock(self):
        self.seed()
        self.assertEqual(self.run_worker("--reconcile").returncode, 0)
        self.assertEqual(self.read()["state"], "worker_starting")
        self.seed("running", 1200)
        lock_path = self.data / (JOB_ID + ".json.lock")
        with lock_path.open("a+b") as handle:
            lock_path.chmod(0o640)
            fcntl.flock(handle, fcntl.LOCK_EX)
            self.assertEqual(self.run_worker("--reconcile").returncode, 0)
            self.assertEqual(self.read()["state"], "running")
            fcntl.flock(handle, fcntl.LOCK_UN)
        self.assertEqual(self.run_worker("--reconcile").returncode, 0)
        self.assertEqual(self.read()["failure_category"], "worker_runtime_failed")

    def test_corrupt_or_orphan_marker_does_not_block_other_reconciliation(self):
        broken_id = "job_" + "b" * 32
        orphan_id = "job_" + "c" * 32
        self.seed("worker_starting", 90)
        self.seed("worker_starting", 90, broken_id)
        broken = self.data / (broken_id + ".json")
        broken.write_text("{malformed fixture JSON")
        orphan = self.data / ("pending_" + orphan_id + ".mark")
        orphan.touch(mode=0o640)
        result = self.run_worker("--reconcile")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.read()["failure_category"], "worker_start_failed")
        self.assertEqual(broken.read_text(), "{malformed fixture JSON")
        self.assertFalse((self.data / ("pending_" + broken_id + ".mark")).exists())
        self.assertFalse(orphan.exists())

    def test_health_bootstrap_failure_is_sanitized(self):
        self.bootstrap.write_text("<?php throw new RuntimeException(" + self.php_string(SECRET) + ");")
        result = self.run_worker("--health-check", "--record-health")
        self.assertNotEqual(result.returncode, 0)
        probe = json.loads(result.stdout)
        self.assertFalse(probe["ok"])
        self.assertEqual(probe["failure_category"], "worker_bootstrap_failed")
        self.assertNotIn(SECRET, (self.data / "worker-probe.json").read_text() + result.stderr)

    def test_health_early_exit_zero_is_failed_and_recorded(self):
        self.bootstrap.write_text("<?php exit(0);")
        result = self.run_worker("--health-check", "--record-health")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(json.loads(result.stdout)["ok"])
        saved = json.loads((self.data / "worker-probe.json").read_text())
        self.assertEqual(saved["failure_category"], "worker_bootstrap_failed")

    def test_packaged_trait_reuses_already_loaded_runtime_helper(self):
        packaged = self.folder / "packaged_module"
        packaged_helper = packaged / "bin/sls_mass_notify/sls_announcement_jobs.php"
        packaged_helper.parent.mkdir(parents=True)
        # Model deployment: runtime worker and packaged module contain separate
        # paths to the same helper class. require_once alone cannot deduplicate it.
        packaged_helper.write_text(self.helper.read_text())
        packaged_trait = packaged / "AnnouncementDelivery.php"
        packaged_trait.write_text((CANDIDATE / "AnnouncementDelivery.php").read_text())
        self.module()
        bootstrap = self.bootstrap.read_text()
        assertion = (
            "if (!class_exists('SlsAnnouncementJobStore', false)) { throw new RuntimeException('Runtime helper was not loaded'); }\n"
            + "require " + self.php_string(str(packaged_trait)) + ";\n"
            + "if ((new ReflectionClass('SlsAnnouncementJobStore'))->getFileName() !== "
            + self.php_string(str(self.helper)) + ") { throw new RuntimeException('Runtime helper was replaced'); }\n"
        )
        self.bootstrap.write_text(bootstrap.replace("<?php\n", "<?php\n" + assertion, 1))
        result = self.run_worker("--health-check", "--record-health")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        probe = json.loads(result.stdout)
        self.assertTrue(probe["ok"])
        self.assertTrue(probe["bootstrap"])
        self.assertTrue(probe["module_loaded"])
        self.seed()
        result = self.run_worker("--supervise", JOB_ID)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.read()["state"], "complete")

    def test_invalid_job_argument_does_not_bootstrap(self):
        marker = self.folder / "bootstrap-was-loaded"
        self.bootstrap.write_text("<?php file_put_contents(" + self.php_string(str(marker)) + ", 'unexpected');")
        result = self.run_worker("job_invalid; payload")
        self.assertEqual(result.returncode, 2)
        self.assertFalse(marker.exists())
        self.assertEqual(list(self.data.iterdir()), [])


if __name__ == "__main__":
    unittest.main()
