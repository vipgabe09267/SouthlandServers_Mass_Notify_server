#!/usr/bin/env python3
"""Focused regressions for Lightning manual-test call results and status isolation."""

import importlib.util
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / "slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py"
SPEC = importlib.util.spec_from_file_location("sls_xweather_worker", WORKER)
WORKER_MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(WORKER_MODULE)


class XweatherManualTestStatusTests(unittest.TestCase):
    def test_page_hold_covers_complete_audio_with_margin(self):
        with tempfile.TemporaryDirectory() as directory:
            sounds = Path(directory)
            sound_file = sounds / "safe" / "sound.wav"
            sound_file.parent.mkdir()
            sound_file.write_bytes(b"not-read-by-the-mocked-soxi")
            completed = SimpleNamespace(stdout="12.25\n")
            with mock.patch.object(WORKER_MODULE, "ASTERISK_SOUNDS_DIR", sounds), \
                    mock.patch.object(WORKER_MODULE.subprocess, "run", return_value=completed):
                self.assertEqual(WORKER_MODULE.audio_page_hold_seconds("safe/sound"), 15)

    def test_archived_queue_result_keeps_its_extension(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            spool = root / "outgoing"
            done = root / "outgoing_done"
            temporary = root / "tmp"
            spool.mkdir()
            temporary.mkdir()
            real_mkstemp = tempfile.mkstemp

            def local_mkstemp(*, prefix, suffix, dir, text):
                return real_mkstemp(prefix=prefix, suffix=suffix, dir=temporary, text=text)

            with mock.patch.object(WORKER_MODULE, "SPOOL_DIR", spool), \
                    mock.patch.object(WORKER_MODULE, "SPOOL_DONE_DIR", done), \
                    mock.patch.object(WORKER_MODULE.tempfile, "mkstemp", side_effect=local_mkstemp), \
                    mock.patch.object(WORKER_MODULE, "audio_page_hold_seconds", return_value=15), \
                    mock.patch.object(WORKER_MODULE, "wait_for_slot"), \
                    mock.patch.object(WORKER_MODULE.os, "geteuid", return_value=1000):
                queued, results, page_hold_seconds = WORKER_MODULE.queue_audio(["1000"], "safe/sound", archive=True)

            self.assertEqual(queued, 1)
            self.assertEqual(page_hold_seconds, 15)
            self.assertEqual(len(results), 1)
            archived_path, extension = results[0]
            self.assertEqual(extension, "1000")
            self.assertEqual(archived_path.parent, done)
            queued_call = spool / archived_path.name
            self.assertTrue(queued_call.is_file())
            call_text = queued_call.read_text(encoding="utf-8")
            self.assertIn("WaitTime: 45\n", call_text)
            self.assertIn("Data: 15\n", call_text)

    def test_completed_archive_is_removed_without_error(self):
        with tempfile.TemporaryDirectory() as directory:
            result = Path(directory) / "opaque.call"
            result.write_text("Status: Completed\n", encoding="utf-8")

            WORKER_MODULE.wait_for_archived_calls([(result, "1000")], timeout=0)

            self.assertFalse(result.exists())

    def test_expired_archive_reports_extension_without_filename(self):
        with tempfile.TemporaryDirectory() as directory:
            result = Path(directory) / "sls_xweather_random.call"
            result.write_text("Status: Expired\n", encoding="utf-8")

            with self.assertRaisesRegex(RuntimeError, "Extension 1000") as raised:
                WORKER_MODULE.wait_for_archived_calls([(result, "1000")], timeout=0)

            message = str(raised.exception)
            self.assertIn("did not answer within 30 seconds", message)
            self.assertIn("Asterisk status: Expired", message)
            self.assertNotIn(result.name, message)
            self.assertFalse(result.exists())

    def test_missing_archive_reports_extension_without_filename(self):
        with tempfile.TemporaryDirectory() as directory:
            result = Path(directory) / "sls_xweather_random.call"

            with self.assertRaisesRegex(RuntimeError, "timed out waiting") as raised:
                WORKER_MODULE.wait_for_archived_calls([(result, "1001")], timeout=0)

            message = str(raised.exception)
            self.assertIn("Extension 1001", message)
            self.assertNotIn(result.name, message)

    def test_manual_test_outcome_does_not_write_live_delivery_keys(self):
        updates = []
        with mock.patch.object(WORKER_MODULE, "atomic_json_update", side_effect=lambda path, patch: updates.append(patch)):
            WORKER_MODULE.record_xweather_outcome(True, "fault", "simulated failure")

        self.assertEqual(len(updates), 1)
        self.assertEqual(updates[0]["last_xweather_test_status"], "fault")
        self.assertEqual(updates[0]["last_xweather_test_message"], "simulated failure")
        self.assertFalse(any(key.startswith("last_xweather_delivery_") for key in updates[0]))

    def test_live_outcome_keeps_existing_delivery_namespace(self):
        updates = []
        with mock.patch.object(WORKER_MODULE, "atomic_json_update", side_effect=lambda path, patch: updates.append(patch)):
            WORKER_MODULE.record_xweather_outcome(False, "queued", "live delivery queued")

        self.assertEqual(len(updates), 1)
        self.assertEqual(updates[0]["last_xweather_delivery_status"], "queued")
        self.assertEqual(updates[0]["last_xweather_delivery_message"], "live delivery queued")
        self.assertFalse(any(key.startswith("last_xweather_test_") for key in updates[0]))


if __name__ == "__main__":
    unittest.main()
